import React, { useState } from 'react';
import { apiPost } from '@/lib/api';

export default function CheckoutPage() {
    const [bookingId, setBookingId] = useState('');
    const [clientSecret, setClientSecret] = useState<string | null>(null);

    const handleCreate = async () => {
        const res = await apiPost('/api/payments/create-intent', {
            booking_id: bookingId,
        });
        setClientSecret(res.client_secret ?? null);
    };

    return (
        <div className="container mx-auto p-6">
            <h1 className="mb-4 text-2xl font-semibold">Checkout (Stripe)</h1>
            <div className="mb-4">
                <label className="mb-1 block">Booking ID</label>
                <input
                    value={bookingId}
                    onChange={(e) => setBookingId(e.target.value)}
                    className="w-full rounded border border-input bg-background p-2"
                />
            </div>
            <button
                onClick={handleCreate}
                className="rounded bg-primary px-4 py-2 text-primary-foreground"
            >
                Create Payment Intent
            </button>
            {clientSecret && (
                <div className="mt-4 rounded border border-border bg-muted p-4">
                    <strong>Client Secret:</strong>
                    <div className="break-words">{clientSecret}</div>
                </div>
            )}
        </div>
    );
}
