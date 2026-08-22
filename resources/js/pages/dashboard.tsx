import { Head, Link } from '@inertiajs/react';
import {
    ArrowRight,
    CalendarCheck,
    CreditCard,
    Star,
    Wallet,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { extractErrorMessage } from '@/lib/api';
import {
    emptyBookingSummary,
    fetchClientBookings,
    formatStayDate,
    nightsLabel,
    stageBadgeClasses,
    stageLabels,
} from '@/lib/bookings';
import type { BookingSummary, ClientBooking } from '@/lib/bookings';
import { formatPrice } from '@/lib/property-search';
import { dashboard } from '@/routes';

const statCards = (summary: BookingSummary) => [
    {
        key: 'upcoming',
        label: 'Upcoming stays',
        value: String(summary.upcoming),
        hint: `${summary.total} reservation${summary.total === 1 ? '' : 's'} in total`,
        icon: CalendarCheck,
    },
    {
        key: 'pending',
        label: 'Payments due',
        value: String(summary.pending_payments),
        hint:
            summary.amount_due > 0
                ? `${formatPrice(summary.amount_due)} outstanding`
                : 'Nothing outstanding',
        icon: CreditCard,
    },
    {
        key: 'paid',
        label: 'Total paid',
        value: formatPrice(summary.amount_paid),
        hint: 'Across all settled reservations',
        icon: Wallet,
    },
    {
        key: 'reviews',
        label: 'Reviews due',
        value: String(summary.reviews_due),
        hint: 'Completed stays waiting for feedback',
        icon: Star,
    },
];

export default function Dashboard() {
    const [bookings, setBookings] = useState<ClientBooking[]>([]);
    const [summary, setSummary] = useState<BookingSummary>(emptyBookingSummary);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        fetchClientBookings()
            .then((response) => {
                setBookings(response.bookings);
                setSummary(response.summary);
            })
            .catch((error) => toast.error(extractErrorMessage(error)))
            .finally(() => setLoading(false));
    }, []);

    const nextStay = useMemo(() => {
        return (
            bookings
                .filter(
                    (booking) =>
                        booking.stage === 'upcoming' ||
                        booking.stage === 'staying',
                )
                .sort((a, b) =>
                    (a.check_in ?? '').localeCompare(b.check_in ?? ''),
                )[0] ?? null
        );
    }, [bookings]);

    const recentBookings = useMemo(() => bookings.slice(0, 5), [bookings]);

    return (
        <>
            <Head title="Dashboard" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p className="text-xs tracking-[0.22em] text-muted-foreground uppercase">
                            Client area
                        </p>
                        <h1 className="mt-2 text-3xl font-semibold tracking-tight">
                            Your travel dashboard
                        </h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Reservations, payment status, receipts, and reviews
                            in one place.
                        </p>
                    </div>
                    <Button asChild>
                        <Link href="/properties">Browse stays</Link>
                    </Button>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {statCards(summary).map((card) => (
                        <div
                            key={card.key}
                            className="rounded-xl border border-border bg-card p-5 shadow-sm"
                        >
                            <div className="flex items-center justify-between">
                                <div className="text-xs tracking-[0.18em] text-muted-foreground uppercase">
                                    {card.label}
                                </div>
                                <card.icon className="size-4 text-muted-foreground" />
                            </div>
                            <div className="mt-3 text-3xl font-semibold tracking-tight">
                                {loading ? '—' : card.value}
                            </div>
                            <div className="mt-2 text-sm text-muted-foreground">
                                {card.hint}
                            </div>
                        </div>
                    ))}
                </div>

                <div className="grid gap-4 lg:grid-cols-[1.1fr_0.9fr]">
                    <section className="rounded-xl border border-border bg-card p-6 shadow-sm">
                        <div className="text-xs tracking-[0.18em] text-muted-foreground uppercase">
                            Next stay
                        </div>

                        {loading ? (
                            <p className="mt-4 text-sm text-muted-foreground">
                                Loading your reservations…
                            </p>
                        ) : nextStay ? (
                            <div className="mt-4 space-y-4">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h2 className="text-2xl font-semibold tracking-tight">
                                            {nextStay.property?.title ??
                                                `Booking #${nextStay.id}`}
                                        </h2>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            {nextStay.property?.address ??
                                                'Address to be confirmed'}
                                        </p>
                                    </div>
                                    <span
                                        className={`rounded-full border px-3 py-1 text-xs font-medium ${stageBadgeClasses[nextStay.stage ?? 'upcoming']}`}
                                    >
                                        {
                                            stageLabels[
                                                nextStay.stage ?? 'upcoming'
                                            ]
                                        }
                                    </span>
                                </div>

                                <div className="grid gap-3 sm:grid-cols-3">
                                    <div className="rounded-lg border border-border bg-muted/40 p-3">
                                        <div className="text-[11px] tracking-[0.16em] text-muted-foreground uppercase">
                                            Check in
                                        </div>
                                        <div className="mt-1 text-sm font-medium">
                                            {formatStayDate(nextStay.check_in)}
                                        </div>
                                    </div>
                                    <div className="rounded-lg border border-border bg-muted/40 p-3">
                                        <div className="text-[11px] tracking-[0.16em] text-muted-foreground uppercase">
                                            Check out
                                        </div>
                                        <div className="mt-1 text-sm font-medium">
                                            {formatStayDate(nextStay.check_out)}
                                        </div>
                                    </div>
                                    <div className="rounded-lg border border-border bg-muted/40 p-3">
                                        <div className="text-[11px] tracking-[0.16em] text-muted-foreground uppercase">
                                            Total
                                        </div>
                                        <div className="mt-1 text-sm font-medium">
                                            {formatPrice(
                                                nextStay.total_price,
                                                nextStay.currency,
                                            )}{' '}
                                            · {nightsLabel(nextStay.nights)}
                                        </div>
                                    </div>
                                </div>

                                <div className="flex flex-wrap gap-3">
                                    <Button asChild size="sm">
                                        <Link href="/client/bookings">
                                            Manage reservation
                                        </Link>
                                    </Button>
                                    {nextStay.property?.slug ? (
                                        <Button
                                            asChild
                                            size="sm"
                                            variant="outline"
                                        >
                                            <Link
                                                href={`/properties/${nextStay.property.slug}`}
                                            >
                                                View property
                                            </Link>
                                        </Button>
                                    ) : null}
                                </div>
                            </div>
                        ) : (
                            <div className="mt-4 rounded-lg border border-dashed border-border p-6 text-center text-sm text-muted-foreground">
                                No upcoming stays yet.{' '}
                                <Link
                                    href="/properties"
                                    className="font-medium underline underline-offset-4"
                                >
                                    Find a place to stay
                                </Link>
                                .
                            </div>
                        )}
                    </section>

                    <section className="rounded-xl border border-border bg-card p-6 shadow-sm">
                        <div className="flex items-end justify-between gap-4">
                            <div>
                                <div className="text-xs tracking-[0.18em] text-muted-foreground uppercase">
                                    Recent activity
                                </div>
                                <h2 className="mt-2 text-xl font-semibold tracking-tight">
                                    Latest reservations
                                </h2>
                            </div>
                            <Link
                                href="/client/bookings"
                                className="inline-flex items-center gap-1 text-sm font-medium text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                            >
                                All bookings <ArrowRight className="size-3.5" />
                            </Link>
                        </div>

                        <div className="mt-5 space-y-3">
                            {loading ? (
                                <div className="rounded-lg border border-border bg-muted/40 px-4 py-6 text-center text-sm text-muted-foreground">
                                    Loading…
                                </div>
                            ) : recentBookings.length ? (
                                recentBookings.map((booking) => (
                                    <div
                                        key={booking.id}
                                        className="flex items-center justify-between gap-3 rounded-lg border border-border bg-muted/40 px-4 py-3"
                                    >
                                        <div className="min-w-0">
                                            <div className="truncate text-sm font-medium">
                                                {booking.property?.title ??
                                                    `Booking #${booking.id}`}
                                            </div>
                                            <div className="mt-1 text-xs text-muted-foreground">
                                                {formatStayDate(
                                                    booking.check_in,
                                                )}{' '}
                                                →{' '}
                                                {formatStayDate(
                                                    booking.check_out,
                                                )}
                                            </div>
                                        </div>
                                        <span
                                            className={`shrink-0 rounded-full border px-2.5 py-1 text-[11px] font-medium ${stageBadgeClasses[booking.stage ?? 'upcoming']}`}
                                        >
                                            {
                                                stageLabels[
                                                    booking.stage ?? 'upcoming'
                                                ]
                                            }
                                        </span>
                                    </div>
                                ))
                            ) : (
                                <div className="rounded-lg border border-dashed border-border px-4 py-8 text-center text-sm text-muted-foreground">
                                    No bookings yet. Reserve a property to start
                                    your history.
                                </div>
                            )}
                        </div>
                    </section>
                </div>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
