@php
    $currency = $booking->property?->currency ?: 'USD';
    $payment = $booking->payment;
@endphp
<!doctype html>
<html>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Receipt #{{ $booking->id }}</title>
        <style>
            body { font-family: DejaVu Sans, Arial, sans-serif; color:#111; font-size: 12px; }
            .header { border-bottom:2px solid #111; padding-bottom:12px; margin-bottom:20px }
            .brand { font-size:22px; font-weight:bold; letter-spacing:1px }
            .muted { color:#666 }
            .details { width:100%; border-collapse: collapse; margin-bottom:18px }
            .details td, .details th { padding:8px; border:1px solid #ddd; text-align:left }
            .details th { width:35%; background:#f7f7f7 }
            .total td { font-size:14px; font-weight:bold }
            .status { display:inline-block; padding:3px 8px; border:1px solid #ddd; border-radius:10px }
        </style>
    </head>
    <body>
        <div class="header">
            <div class="brand">{{ config('app.name', 'Kozy') }}</div>
            <div class="muted">Booking receipt #{{ $booking->id }} &middot; issued {{ now()->toFormattedDateString() }}</div>
        </div>

        <table class="details">
            <tr><th>Property</th><td>{{ $booking->property?->title ?? 'Property removed' }}</td></tr>
            <tr><th>Address</th><td>{{ $booking->property?->address ?? '—' }}</td></tr>
            <tr><th>Guest</th><td>{{ $booking->client?->name }} ({{ $booking->client?->email }})</td></tr>
            <tr><th>Check in</th><td>{{ $booking->check_in?->toFormattedDateString() }}</td></tr>
            <tr><th>Check out</th><td>{{ $booking->check_out?->toFormattedDateString() }}</td></tr>
            <tr><th>Nights</th><td>{{ $booking->nights }}</td></tr>
            <tr><th>Guests</th><td>{{ $booking->guests }}</td></tr>
            <tr><th>Booking status</th><td><span class="status">{{ ucfirst($booking->status) }}</span></td></tr>
        </table>

        <table class="details">
            <tr><th>Payment status</th><td><span class="status">{{ ucfirst($payment?->status ?? 'pending') }}</span></td></tr>
            <tr><th>Payment method</th><td>{{ $payment?->method ?? '—' }}</td></tr>
            <tr><th>Reference</th><td>{{ $payment?->transaction_ref ?? '—' }}</td></tr>
            <tr><th>Paid at</th><td>{{ $payment?->paid_at?->toDayDateTimeString() ?? 'Not settled yet' }}</td></tr>
            <tr><th>Nightly rate</th><td>{{ $currency }} {{ number_format((float) ($booking->property?->price_per_night ?? 0), 2) }}</td></tr>
            <tr class="total"><th>Total</th><td>{{ $currency }} {{ number_format((float) $booking->total_price, 2) }}</td></tr>
        </table>

        <p class="muted">Thank you for booking with {{ config('app.name', 'Kozy') }}. Keep this receipt for your records.</p>
    </body>
</html>
