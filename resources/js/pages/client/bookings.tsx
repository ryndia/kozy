import { Head, Link } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
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
import { extractErrorMessage } from '@/lib/api';
import {
    cancelClientBooking,
    confirmBookingPayment,
    createPaymentIntent,
    emptyBookingSummary,
    fetchClientBookings,
    formatStayDate,
    nightsLabel,
    paymentBadgeClasses,
    stageBadgeClasses,
    stageLabels,
    submitBookingReview,
} from '@/lib/bookings';
import type {
    BookingStage,
    BookingSummary,
    ClientBooking,
} from '@/lib/bookings';
import { formatPrice } from '@/lib/property-search';
import client from '@/routes/client';

type Filter = 'all' | BookingStage;

const filters: Array<{ key: Filter; label: string }> = [
    { key: 'all', label: 'All' },
    { key: 'upcoming', label: 'Upcoming' },
    { key: 'staying', label: 'Staying now' },
    { key: 'past', label: 'Completed' },
    { key: 'cancelled', label: 'Cancelled' },
];

export default function MyBookings() {
    const [bookings, setBookings] = useState<ClientBooking[]>([]);
    const [summary, setSummary] = useState<BookingSummary>(emptyBookingSummary);
    const [loading, setLoading] = useState(true);
    const [filter, setFilter] = useState<Filter>('all');
    const [busyBookingId, setBusyBookingId] = useState<number | null>(null);
    const [cancelTarget, setCancelTarget] = useState<ClientBooking | null>(
        null,
    );
    const [clientSecrets, setClientSecrets] = useState<Record<number, string>>(
        {},
    );
    const [reviewDrafts, setReviewDrafts] = useState<
        Record<number, { rating: string; comment: string }>
    >({});

    const applyBookings = (response: {
        bookings: ClientBooking[];
        summary: BookingSummary;
    }) => {
        setBookings(response.bookings);
        setSummary(response.summary);
    };

    const loadBookings = async () => {
        try {
            applyBookings(await fetchClientBookings());
        } catch (error) {
            toast.error(extractErrorMessage(error));
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchClientBookings()
            .then(applyBookings)
            .catch((error) => toast.error(extractErrorMessage(error)))
            .finally(() => setLoading(false));
    }, []);

    const visibleBookings = useMemo(() => {
        if (filter === 'all') {
            return bookings;
        }

        return bookings.filter((booking) => booking.stage === filter);
    }, [bookings, filter]);

    const updateReviewDraft = (
        bookingId: number,
        key: 'rating' | 'comment',
        value: string,
    ) => {
        setReviewDrafts((current) => ({
            ...current,
            [bookingId]: {
                rating: current[bookingId]?.rating ?? '5',
                comment: current[bookingId]?.comment ?? '',
                [key]: value,
            },
        }));
    };

    /**
     * Start a card payment when Stripe is configured; otherwise fall back to the
     * manual settlement the API allows outside production.
     */
    const handlePay = async (booking: ClientBooking) => {
        setBusyBookingId(booking.id);

        try {
            const intent = await createPaymentIntent(booking.id);

            if (intent.client_secret) {
                setClientSecrets((current) => ({
                    ...current,
                    [booking.id]: intent.client_secret,
                }));
                toast.success(
                    'Card payment started. Complete it with your card details.',
                );

                return;
            }

            await settlePayment(booking, intent.payment_intent_id);
        } catch (error) {
            const stripeMissing =
                error &&
                typeof error === 'object' &&
                (error as { stripe_configured?: boolean }).stripe_configured ===
                    false;

            if (stripeMissing) {
                await settlePayment(booking);

                return;
            }

            toast.error(extractErrorMessage(error));
        } finally {
            setBusyBookingId(null);
        }
    };

    const settlePayment = async (
        booking: ClientBooking,
        paymentIntentId?: string,
    ) => {
        try {
            const response = await confirmBookingPayment(
                booking.id,
                paymentIntentId,
            );
            toast.success(response.message ?? 'Payment recorded.');
            await loadBookings();
        } catch (error) {
            toast.error(extractErrorMessage(error));
        }
    };

    const handleCancel = async () => {
        if (!cancelTarget) {
            return;
        }

        setBusyBookingId(cancelTarget.id);

        try {
            const response = await cancelClientBooking(cancelTarget.id);
            toast.success(response.message ?? 'Reservation cancelled.');
            setCancelTarget(null);
            await loadBookings();
        } catch (error) {
            toast.error(extractErrorMessage(error));
        } finally {
            setBusyBookingId(null);
        }
    };

    const handleSubmitReview = async (booking: ClientBooking) => {
        const draft = reviewDrafts[booking.id] ?? { rating: '5', comment: '' };
        setBusyBookingId(booking.id);

        try {
            await submitBookingReview(
                booking,
                Number(draft.rating || 5),
                draft.comment,
            );
            toast.success('Your review has been posted.');
            await loadBookings();
        } catch (error) {
            toast.error(extractErrorMessage(error));
        } finally {
            setBusyBookingId(null);
        }
    };

    return (
        <>
            <Head title="My bookings" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p className="text-xs tracking-[0.22em] text-muted-foreground uppercase">
                            Client area
                        </p>
                        <h1 className="mt-2 text-3xl font-semibold tracking-tight">
                            Booking history
                        </h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Reservations, payment progress, receipts, and review
                            forms in one place.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2 text-sm">
                        <span className="rounded-full border border-border bg-card px-3 py-1.5">
                            {summary.total} bookings
                        </span>
                        <span className="rounded-full border border-border bg-card px-3 py-1.5">
                            {summary.pending_payments} to pay
                        </span>
                        <span className="rounded-full border border-border bg-card px-3 py-1.5">
                            {summary.reviews_due} to review
                        </span>
                    </div>
                </div>

                <div className="flex flex-wrap gap-2">
                    {filters.map((option) => (
                        <button
                            key={option.key}
                            type="button"
                            onClick={() => setFilter(option.key)}
                            className={`rounded-full border px-4 py-1.5 text-sm font-medium transition ${
                                filter === option.key
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'border-border bg-card text-muted-foreground hover:text-foreground'
                            }`}
                        >
                            {option.label}
                        </button>
                    ))}
                </div>

                <div className="space-y-5">
                    {loading ? (
                        <div className="rounded-xl border border-border bg-card p-8 text-center text-muted-foreground">
                            Loading your reservations…
                        </div>
                    ) : visibleBookings.length ? (
                        visibleBookings.map((booking) => {
                            const currency = booking.currency ?? 'USD';
                            const draft = reviewDrafts[booking.id] ?? {
                                rating: '5',
                                comment: '',
                            };
                            const paymentStatus =
                                booking.payment?.status ?? 'pending';
                            const busy = busyBookingId === booking.id;

                            return (
                                <section
                                    key={booking.id}
                                    className="overflow-hidden rounded-xl border border-border bg-card shadow-sm"
                                >
                                    <div className="grid gap-0 lg:grid-cols-[260px_1fr]">
                                        <div className="bg-muted">
                                            {booking.property?.image ? (
                                                <img
                                                    src={booking.property.image}
                                                    alt={
                                                        booking.property
                                                            ?.title ||
                                                        'Booked property'
                                                    }
                                                    className="h-full min-h-[200px] w-full object-cover"
                                                />
                                            ) : (
                                                <div className="flex min-h-[200px] items-center justify-center text-sm text-muted-foreground">
                                                    No property image
                                                </div>
                                            )}
                                        </div>

                                        <div className="p-6">
                                            <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                                <div>
                                                    <div className="text-xs tracking-[0.18em] text-muted-foreground uppercase">
                                                        Booking #{booking.id}
                                                    </div>
                                                    <h2 className="mt-2 text-2xl font-semibold tracking-tight">
                                                        {booking.property
                                                            ?.title ||
                                                            'Booked stay'}
                                                    </h2>
                                                    <p className="mt-1 text-sm text-muted-foreground">
                                                        {booking.property
                                                            ?.address ||
                                                            'No address provided yet.'}
                                                    </p>
                                                </div>
                                                <div className="flex flex-wrap gap-2">
                                                    <span
                                                        className={`rounded-full border px-3 py-1 text-xs font-medium ${stageBadgeClasses[booking.stage ?? 'upcoming']}`}
                                                    >
                                                        {
                                                            stageLabels[
                                                                booking.stage ??
                                                                    'upcoming'
                                                            ]
                                                        }
                                                    </span>
                                                    <span
                                                        className={`rounded-full border px-3 py-1 text-xs font-medium ${paymentBadgeClasses[paymentStatus] ?? paymentBadgeClasses.pending}`}
                                                    >
                                                        Payment: {paymentStatus}
                                                    </span>
                                                </div>
                                            </div>

                                            <div className="mt-5 grid gap-3 sm:grid-cols-4">
                                                <div className="rounded-lg border border-border bg-muted/40 p-3">
                                                    <div className="text-[11px] tracking-[0.16em] text-muted-foreground uppercase">
                                                        Check in
                                                    </div>
                                                    <div className="mt-1 text-sm font-medium">
                                                        {formatStayDate(
                                                            booking.check_in,
                                                        )}
                                                    </div>
                                                </div>
                                                <div className="rounded-lg border border-border bg-muted/40 p-3">
                                                    <div className="text-[11px] tracking-[0.16em] text-muted-foreground uppercase">
                                                        Check out
                                                    </div>
                                                    <div className="mt-1 text-sm font-medium">
                                                        {formatStayDate(
                                                            booking.check_out,
                                                        )}
                                                    </div>
                                                </div>
                                                <div className="rounded-lg border border-border bg-muted/40 p-3">
                                                    <div className="text-[11px] tracking-[0.16em] text-muted-foreground uppercase">
                                                        Guests
                                                    </div>
                                                    <div className="mt-1 text-sm font-medium">
                                                        {booking.guests || 1} ·{' '}
                                                        {nightsLabel(
                                                            booking.nights,
                                                        )}
                                                    </div>
                                                </div>
                                                <div className="rounded-lg border border-border bg-muted/40 p-3">
                                                    <div className="text-[11px] tracking-[0.16em] text-muted-foreground uppercase">
                                                        Total
                                                    </div>
                                                    <div className="mt-1 text-sm font-medium">
                                                        {formatPrice(
                                                            booking.total_price,
                                                            currency,
                                                        )}
                                                    </div>
                                                </div>
                                            </div>

                                            <div className="mt-5 grid gap-4 xl:grid-cols-2">
                                                <div className="rounded-lg border border-border bg-muted/40 p-5">
                                                    <div className="text-[11px] font-semibold tracking-[0.16em] text-muted-foreground uppercase">
                                                        Payment
                                                    </div>
                                                    <div className="mt-3 space-y-1.5 text-sm text-muted-foreground">
                                                        <div>
                                                            Method:{' '}
                                                            <span className="font-medium text-foreground">
                                                                {booking.payment
                                                                    ?.method ||
                                                                    '—'}
                                                            </span>
                                                        </div>
                                                        <div>
                                                            Amount:{' '}
                                                            <span className="font-medium text-foreground">
                                                                {formatPrice(
                                                                    booking
                                                                        .payment
                                                                        ?.amount ??
                                                                        booking.total_price,
                                                                    currency,
                                                                )}
                                                            </span>
                                                        </div>
                                                        {booking.payment
                                                            ?.transaction_ref ? (
                                                            <div className="break-all">
                                                                Reference:{' '}
                                                                <span className="font-medium text-foreground">
                                                                    {
                                                                        booking
                                                                            .payment
                                                                            .transaction_ref
                                                                    }
                                                                </span>
                                                            </div>
                                                        ) : null}
                                                        {booking.payment
                                                            ?.paid_at ? (
                                                            <div>
                                                                Paid at:{' '}
                                                                <span className="font-medium text-foreground">
                                                                    {new Date(
                                                                        booking
                                                                            .payment
                                                                            .paid_at,
                                                                    ).toLocaleString()}
                                                                </span>
                                                            </div>
                                                        ) : null}
                                                    </div>

                                                    <div className="mt-4 flex flex-wrap gap-2">
                                                        {booking.can_pay ? (
                                                            <Button
                                                                size="sm"
                                                                onClick={() =>
                                                                    handlePay(
                                                                        booking,
                                                                    )
                                                                }
                                                                disabled={busy}
                                                            >
                                                                {busy
                                                                    ? 'Processing…'
                                                                    : 'Pay now'}
                                                            </Button>
                                                        ) : null}
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            asChild
                                                        >
                                                            <a
                                                                href={
                                                                    booking.receipt_url
                                                                }
                                                                target="_blank"
                                                                rel="noreferrer"
                                                            >
                                                                Download receipt
                                                            </a>
                                                        </Button>
                                                        {booking.property
                                                            ?.slug ? (
                                                            <Button
                                                                size="sm"
                                                                variant="outline"
                                                                asChild
                                                            >
                                                                <Link
                                                                    href={`/properties/${booking.property.slug}`}
                                                                >
                                                                    View
                                                                    property
                                                                </Link>
                                                            </Button>
                                                        ) : null}
                                                        {booking.can_cancel ? (
                                                            <Button
                                                                size="sm"
                                                                variant="outline"
                                                                className="text-destructive"
                                                                onClick={() =>
                                                                    setCancelTarget(
                                                                        booking,
                                                                    )
                                                                }
                                                                disabled={busy}
                                                            >
                                                                Cancel booking
                                                            </Button>
                                                        ) : null}
                                                    </div>

                                                    {clientSecrets[
                                                        booking.id
                                                    ] ? (
                                                        <div className="mt-4 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-xs text-sky-900 dark:border-sky-900/60 dark:bg-sky-950/50 dark:text-sky-100">
                                                            <div className="font-semibold tracking-[0.14em] uppercase">
                                                                Stripe client
                                                                secret
                                                            </div>
                                                            <div className="mt-2 break-all">
                                                                {
                                                                    clientSecrets[
                                                                        booking
                                                                            .id
                                                                    ]
                                                                }
                                                            </div>
                                                            <Button
                                                                size="sm"
                                                                className="mt-3"
                                                                onClick={() =>
                                                                    settlePayment(
                                                                        booking,
                                                                        booking
                                                                            .payment
                                                                            ?.transaction_ref ??
                                                                            undefined,
                                                                    )
                                                                }
                                                                disabled={busy}
                                                            >
                                                                I have completed
                                                                the payment
                                                            </Button>
                                                        </div>
                                                    ) : null}
                                                </div>

                                                <div className="rounded-lg border border-border bg-muted/40 p-5">
                                                    <div className="text-[11px] font-semibold tracking-[0.16em] text-muted-foreground uppercase">
                                                        Review
                                                    </div>
                                                    {booking.review ? (
                                                        <div className="mt-3 space-y-2 text-sm">
                                                            <div className="font-medium text-amber-600 dark:text-amber-400">
                                                                {'★'.repeat(
                                                                    booking
                                                                        .review
                                                                        .rating ??
                                                                        0,
                                                                )}
                                                                {'☆'.repeat(
                                                                    Math.max(
                                                                        0,
                                                                        5 -
                                                                            (booking
                                                                                .review
                                                                                .rating ??
                                                                                0),
                                                                    ),
                                                                )}
                                                            </div>
                                                            <p className="text-muted-foreground">
                                                                {
                                                                    booking
                                                                        .review
                                                                        .comment
                                                                }
                                                            </p>
                                                            <div className="text-xs tracking-[0.14em] text-muted-foreground uppercase">
                                                                Status:{' '}
                                                                {
                                                                    booking
                                                                        .review
                                                                        .status
                                                                }
                                                            </div>
                                                        </div>
                                                    ) : booking.can_review ? (
                                                        <div className="mt-3 space-y-3">
                                                            <label className="block text-sm">
                                                                <span className="mb-2 block font-medium">
                                                                    Rating
                                                                </span>
                                                                <select
                                                                    value={
                                                                        draft.rating
                                                                    }
                                                                    onChange={(
                                                                        event,
                                                                    ) =>
                                                                        updateReviewDraft(
                                                                            booking.id,
                                                                            'rating',
                                                                            event
                                                                                .target
                                                                                .value,
                                                                        )
                                                                    }
                                                                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                                                >
                                                                    {[
                                                                        5, 4, 3,
                                                                        2, 1,
                                                                    ].map(
                                                                        (
                                                                            rating,
                                                                        ) => (
                                                                            <option
                                                                                key={
                                                                                    rating
                                                                                }
                                                                                value={
                                                                                    rating
                                                                                }
                                                                            >
                                                                                {
                                                                                    rating
                                                                                }{' '}
                                                                                star
                                                                                {rating ===
                                                                                1
                                                                                    ? ''
                                                                                    : 's'}
                                                                            </option>
                                                                        ),
                                                                    )}
                                                                </select>
                                                            </label>
                                                            <label className="block text-sm">
                                                                <span className="mb-2 block font-medium">
                                                                    Comment
                                                                </span>
                                                                <textarea
                                                                    value={
                                                                        draft.comment
                                                                    }
                                                                    onChange={(
                                                                        event,
                                                                    ) =>
                                                                        updateReviewDraft(
                                                                            booking.id,
                                                                            'comment',
                                                                            event
                                                                                .target
                                                                                .value,
                                                                        )
                                                                    }
                                                                    rows={4}
                                                                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                                                    placeholder="Share your stay experience"
                                                                />
                                                            </label>
                                                            <Button
                                                                size="sm"
                                                                onClick={() =>
                                                                    handleSubmitReview(
                                                                        booking,
                                                                    )
                                                                }
                                                                disabled={
                                                                    busy ||
                                                                    !draft.comment.trim()
                                                                }
                                                            >
                                                                {busy
                                                                    ? 'Posting…'
                                                                    : 'Post review'}
                                                            </Button>
                                                        </div>
                                                    ) : (
                                                        <p className="mt-3 text-sm text-muted-foreground">
                                                            A review form
                                                            appears here once
                                                            the stay is
                                                            completed.
                                                        </p>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </section>
                            );
                        })
                    ) : (
                        <div className="rounded-xl border border-dashed border-border bg-card p-8 text-center text-muted-foreground">
                            {filter === 'all'
                                ? 'No bookings found yet. Reserve a property to start building your trip history.'
                                : `No ${stageLabels[filter].toLowerCase()} bookings.`}{' '}
                            <Link
                                href="/properties"
                                className="font-medium underline underline-offset-4"
                            >
                                Browse stays
                            </Link>
                        </div>
                    )}
                </div>
            </div>

            <Dialog
                open={Boolean(cancelTarget)}
                onOpenChange={(open) => !open && setCancelTarget(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Cancel this reservation?</DialogTitle>
                        <DialogDescription>
                            {cancelTarget?.property?.title} from{' '}
                            {formatStayDate(cancelTarget?.check_in)} to{' '}
                            {formatStayDate(cancelTarget?.check_out)} will be
                            released. Settled payments are marked for refund.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setCancelTarget(null)}
                        >
                            Keep booking
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={handleCancel}
                            disabled={busyBookingId === cancelTarget?.id}
                        >
                            {busyBookingId === cancelTarget?.id
                                ? 'Cancelling…'
                                : 'Cancel booking'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

MyBookings.layout = {
    breadcrumbs: [
        {
            title: 'My bookings',
            href: client.bookings(),
        },
    ],
};
