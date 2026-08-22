import { apiGet, apiPost } from '@/lib/api';

export type BookingStage = 'upcoming' | 'staying' | 'past' | 'cancelled';

export type ClientBookingPayment = {
    id?: number;
    amount?: number;
    method?: string | null;
    status?: string | null;
    transaction_ref?: string | null;
    paid_at?: string | null;
};

export type ClientBookingReview = {
    id?: number;
    rating?: number;
    comment?: string | null;
    status?: string | null;
    created_at?: string | null;
};

export type ClientBooking = {
    id: number;
    check_in?: string;
    check_out?: string;
    nights?: number;
    guests?: number;
    status?: string;
    stage?: BookingStage;
    total_price?: number;
    currency?: string;
    created_at?: string;
    can_cancel?: boolean;
    can_pay?: boolean;
    can_review?: boolean;
    receipt_url?: string;
    payment?: ClientBookingPayment | null;
    review?: ClientBookingReview | null;
    property?: {
        id?: number;
        title?: string;
        slug?: string;
        address?: string | null;
        currency?: string | null;
        price_per_night?: number;
        check_in_time?: string | null;
        check_out_time?: string | null;
        image?: string | null;
    } | null;
};

export type BookingSummary = {
    total: number;
    upcoming: number;
    staying: number;
    past: number;
    cancelled: number;
    pending_payments: number;
    reviews_due: number;
    amount_paid: number;
    amount_due: number;
};

export const emptyBookingSummary: BookingSummary = {
    total: 0,
    upcoming: 0,
    staying: 0,
    past: 0,
    cancelled: 0,
    pending_payments: 0,
    reviews_due: 0,
    amount_paid: 0,
    amount_due: 0,
};

export const stageLabels: Record<BookingStage, string> = {
    upcoming: 'Upcoming',
    staying: 'Currently staying',
    past: 'Completed',
    cancelled: 'Cancelled',
};

export const stageBadgeClasses: Record<BookingStage, string> = {
    upcoming:
        'border-sky-200 dark:border-sky-900/60 bg-sky-50 dark:bg-sky-950/50 text-sky-700 dark:text-sky-300',
    staying:
        'border-emerald-200 dark:border-emerald-900/60 bg-emerald-50 dark:bg-emerald-950/50 text-emerald-700 dark:text-emerald-300',
    past: 'border-border bg-muted text-muted-foreground',
    cancelled:
        'border-rose-200 dark:border-rose-900/60 bg-rose-50 dark:bg-rose-950/50 text-rose-700 dark:text-rose-300',
};

export const paymentBadgeClasses: Record<string, string> = {
    paid: 'border-emerald-200 dark:border-emerald-900/60 bg-emerald-50 dark:bg-emerald-950/50 text-emerald-700 dark:text-emerald-300',
    pending:
        'border-amber-200 dark:border-amber-900/60 bg-amber-50 dark:bg-amber-950/50 text-amber-700 dark:text-amber-300',
    failed: 'border-rose-200 dark:border-rose-900/60 bg-rose-50 dark:bg-rose-950/50 text-rose-700 dark:text-rose-300',
    refunded: 'border-border bg-muted text-muted-foreground',
};

export const formatStayDate = (value?: string | null) => {
    if (!value) {
        return '—';
    }

    const [year, month, day] = value.split('-').map(Number);

    if (!year || !month || !day) {
        return value;
    }

    return new Date(year, month - 1, day).toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
};

export const nightsLabel = (nights?: number) => {
    const count = nights ?? 0;

    return `${count} night${count === 1 ? '' : 's'}`;
};

export async function fetchClientBookings(): Promise<{
    bookings: ClientBooking[];
    summary: BookingSummary;
}> {
    const response = await apiGet('/api/client/bookings');

    return {
        bookings: response.bookings ?? [],
        summary: { ...emptyBookingSummary, ...(response.summary ?? {}) },
    };
}

export function cancelClientBooking(bookingId: number) {
    return apiPost(`/api/client/bookings/${bookingId}/cancel`);
}

export function createPaymentIntent(bookingId: number) {
    return apiPost('/api/payments/create-intent', { booking_id: bookingId });
}

export function confirmBookingPayment(
    bookingId: number,
    paymentIntentId?: string,
) {
    return apiPost('/api/payments/confirm', {
        booking_id: bookingId,
        ...(paymentIntentId ? { payment_intent_id: paymentIntentId } : {}),
    });
}

export function submitBookingReview(
    booking: ClientBooking,
    rating: number,
    comment: string,
) {
    return apiPost('/api/reviews', {
        property_id: booking.property?.id,
        booking_id: booking.id,
        rating,
        comment,
    });
}
