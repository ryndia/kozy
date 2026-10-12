import React, { useEffect, useMemo, useState } from 'react';
import { usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import CurrencySwitcher from '@/components/currency-switcher';
import DestinationAutocomplete from '@/components/destination-autocomplete';
import PriceDisplay from '@/components/price-display';
import PublicThemeToggle from '@/components/public-theme-toggle';
import { apiGet } from '@/lib/api';
import {
    buildPropertiesUrl,
    buildPropertyUrl,
    defaultPropertySearchFilters,
    formatRoomLine,
    nextIsoDate,
    normalizePropertyImage,
    readPropertySearchFilters,
    toApiSearchParams,
    todayIsoDate,
    type PropertySearchFilters,
    type PublicProperty,
} from '@/lib/property-search';

type PropertyResponse = {
    data: PublicProperty[];
    meta?: {
        total?: number;
    };
};

export default function PropertiesPage() {
    const page = usePage();
    const authUser =
        (page.props as { auth?: { user?: { name?: string } | null } }).auth
            ?.user ?? null;
    const [data, setData] = useState<PropertyResponse | null>(null);
    const [filters, setFilters] = useState<PropertySearchFilters>(
        defaultPropertySearchFilters,
    );
    const [draftFilters, setDraftFilters] = useState<PropertySearchFilters>(
        defaultPropertySearchFilters,
    );
    const [initialized, setInitialized] = useState(false);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        const nextFilters = readPropertySearchFilters(window.location.search);
        setFilters(nextFilters);
        setDraftFilters(nextFilters);
        setInitialized(true);
    }, []);

    useEffect(() => {
        if (!initialized) {
            return;
        }

        setLoading(true);
        apiGet('/api/properties', toApiSearchParams(filters))
            .then((response) => setData(response))
            .catch(console.error)
            .finally(() => setLoading(false));

        window.history.replaceState({}, '', buildPropertiesUrl(filters));
    }, [filters, initialized]);

    const activeFilterCount = useMemo(() => {
        return [
            filters.q,
            filters.checkIn,
            filters.checkOut,
            filters.guests,
            filters.maxPrice,
        ].filter(Boolean).length;
    }, [filters]);

    const updateDraftFilter = (
        key: keyof PropertySearchFilters,
        value: string,
    ) => {
        setDraftFilters((current) => ({ ...current, [key]: value }));
    };

    const submitFilters = (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setFilters(draftFilters);
    };

    const todayIso = useMemo(() => todayIsoDate(), []);
    const minCheckOut = draftFilters.checkIn
        ? nextIsoDate(draftFilters.checkIn)
        : nextIsoDate(todayIso);

    const clearFilters = () => {
        setDraftFilters(defaultPropertySearchFilters);
        setFilters(defaultPropertySearchFilters);
    };

    return (
        <div className="min-h-screen px-4 py-6 text-foreground sm:px-6 lg:px-8">
            <div className="mx-auto max-w-7xl">
                <header className="glass-panel sticky top-4 z-20 mx-auto mb-8 flex w-full max-w-6xl items-center justify-between rounded-full border border-white/30 bg-white/80 px-4 py-3 backdrop-blur sm:px-6 dark:border-white/10 dark:bg-slate-900/75">
                    <a
                        href="/"
                        aria-label="Kozy home"
                        className="flex items-center gap-3 rounded-full transition hover:opacity-80"
                    >
                        <AppLogoIcon className="h-10 w-10 shrink-0" />
                        <div>
                            <div className="text-[10px] tracking-[0.28em] text-muted-foreground uppercase">
                                Kozy
                            </div>
                            <div className="text-sm font-semibold text-foreground">
                                Crystal stays
                            </div>
                        </div>
                    </a>

                    <nav className="hidden items-center gap-6 text-sm text-muted-foreground md:flex">
                        <a
                            href="/"
                            className="transition hover:text-foreground"
                        >
                            Home
                        </a>
                        <a
                            href="/properties"
                            className="transition hover:text-foreground"
                        >
                            Stays
                        </a>
                        <a
                            href="/#experience"
                            className="transition hover:text-foreground"
                        >
                            Experience
                        </a>
                    </nav>

                    <div className="flex items-center gap-3">
                        <CurrencySwitcher />
                        <PublicThemeToggle />
                        {authUser ? (
                            <a
                                href="/client/bookings"
                                className="hidden rounded-full border border-border bg-card px-4 py-2 text-sm font-medium text-foreground transition hover:border-foreground/20 sm:inline-flex"
                            >
                                My bookings
                            </a>
                        ) : (
                            <>
                                <a
                                    href="/login"
                                    className="hidden rounded-full border border-border bg-card px-4 py-2 text-sm font-medium text-foreground transition hover:border-foreground/20 hover:text-foreground sm:inline-flex"
                                >
                                    Log in
                                </a>
                                <a
                                    href="/register"
                                    className="hidden rounded-full border border-border bg-muted px-4 py-2 text-sm font-medium text-foreground transition hover:border-foreground/20 sm:inline-flex"
                                >
                                    Sign up
                                </a>
                            </>
                        )}
                    </div>
                </header>

                <section className="mb-8 rounded-[32px] border border-white/40 bg-white/25 p-6 sm:p-8 dark:border-white/10 dark:bg-white/5">
                    <div className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                        <div>
                            <div className="text-xs tracking-[0.24em] text-muted-foreground uppercase">
                                Collection
                            </div>
                            <h1 className="mt-2 text-3xl font-semibold tracking-[-0.06em] text-foreground sm:text-4xl">
                                Curated stays
                            </h1>
                            <p className="mt-3 max-w-2xl text-sm leading-6 text-muted-foreground">
                                Filter by destination, dates, guests, and budget
                                to only see stays that fit the trip you want to
                                book.
                            </p>
                        </div>
                        <div className="rounded-full border border-white/50 bg-white/40 px-4 py-2 text-sm font-medium text-foreground dark:border-white/10 dark:bg-white/5">
                            {loading
                                ? 'Searching stays...'
                                : `${data?.meta?.total ?? data?.data?.length ?? 0} stays available`}
                        </div>
                    </div>
                </section>

                <section className="mb-8 rounded-[32px] border border-border bg-card p-5 shadow-[0_20px_50px_rgba(15,23,42,0.06)] sm:p-6">
                    <form
                        onSubmit={submitFilters}
                        className="grid gap-4 lg:grid-cols-[1.3fr_0.9fr_0.9fr_0.7fr_0.9fr_auto]"
                    >
                        <label className="text-left">
                            <span className="text-[11px] font-semibold tracking-[0.2em] text-muted-foreground uppercase">
                                Destination
                            </span>
                            <DestinationAutocomplete
                                value={draftFilters.q}
                                onChange={(value) =>
                                    updateDraftFilter('q', value)
                                }
                                availableProperties={data?.data ?? []}
                                placeholder="Title, city, or address"
                                inputClassName="mt-2 w-full rounded-2xl border border-border bg-muted px-4 py-3 text-sm text-foreground placeholder:text-muted-foreground focus:border-ring focus:outline-none"
                            />
                        </label>
                        <label className="text-left">
                            <span className="text-[11px] font-semibold tracking-[0.2em] text-muted-foreground uppercase">
                                Check in
                            </span>
                            <input
                                type="date"
                                min={todayIso}
                                value={draftFilters.checkIn}
                                onChange={(event) =>
                                    updateDraftFilter(
                                        'checkIn',
                                        event.target.value,
                                    )
                                }
                                className="mt-2 w-full rounded-2xl border border-border bg-muted px-4 py-3 text-sm text-foreground focus:border-ring focus:outline-none"
                            />
                        </label>
                        <label className="text-left">
                            <span className="text-[11px] font-semibold tracking-[0.2em] text-muted-foreground uppercase">
                                Check out
                            </span>
                            <input
                                type="date"
                                min={minCheckOut}
                                value={draftFilters.checkOut}
                                onChange={(event) =>
                                    updateDraftFilter(
                                        'checkOut',
                                        event.target.value,
                                    )
                                }
                                className="mt-2 w-full rounded-2xl border border-border bg-muted px-4 py-3 text-sm text-foreground focus:border-ring focus:outline-none"
                            />
                        </label>
                        <label className="text-left">
                            <span className="text-[11px] font-semibold tracking-[0.2em] text-muted-foreground uppercase">
                                Guests
                            </span>
                            <input
                                type="number"
                                min={1}
                                value={draftFilters.guests}
                                onChange={(event) =>
                                    updateDraftFilter(
                                        'guests',
                                        event.target.value,
                                    )
                                }
                                placeholder="Guests"
                                className="mt-2 w-full rounded-2xl border border-border bg-muted px-4 py-3 text-sm text-foreground placeholder:text-muted-foreground focus:border-ring focus:outline-none"
                            />
                        </label>
                        <label className="text-left">
                            <span className="text-[11px] font-semibold tracking-[0.2em] text-muted-foreground uppercase">
                                Max price
                            </span>
                            <input
                                type="number"
                                min={0}
                                value={draftFilters.maxPrice}
                                onChange={(event) =>
                                    updateDraftFilter(
                                        'maxPrice',
                                        event.target.value,
                                    )
                                }
                                placeholder="Any budget"
                                className="mt-2 w-full rounded-2xl border border-border bg-muted px-4 py-3 text-sm text-foreground placeholder:text-muted-foreground focus:border-ring focus:outline-none"
                            />
                        </label>
                        <div className="flex items-end gap-3">
                            <button
                                type="submit"
                                className="crystal-button flex-1 justify-center bg-primary text-primary-foreground hover:bg-primary/90"
                            >
                                Apply filters
                            </button>
                            <button
                                type="button"
                                onClick={clearFilters}
                                className="rounded-2xl border border-border px-4 py-3 text-sm font-medium text-foreground transition hover:border-foreground/20 hover:text-foreground"
                            >
                                Reset
                            </button>
                        </div>
                    </form>

                    {activeFilterCount ? (
                        <div className="mt-4 flex flex-wrap gap-2 text-xs font-medium text-muted-foreground">
                            {filters.q ? (
                                <span className="rounded-full border border-border bg-muted px-3 py-1.5">
                                    Destination: {filters.q}
                                </span>
                            ) : null}
                            {filters.checkIn ? (
                                <span className="rounded-full border border-border bg-muted px-3 py-1.5">
                                    Check in: {filters.checkIn}
                                </span>
                            ) : null}
                            {filters.checkOut ? (
                                <span className="rounded-full border border-border bg-muted px-3 py-1.5">
                                    Check out: {filters.checkOut}
                                </span>
                            ) : null}
                            {filters.guests ? (
                                <span className="rounded-full border border-border bg-muted px-3 py-1.5">
                                    Guests: {filters.guests}
                                </span>
                            ) : null}
                            {filters.maxPrice ? (
                                <span className="rounded-full border border-border bg-muted px-3 py-1.5">
                                    Up to {filters.maxPrice}
                                </span>
                            ) : null}
                        </div>
                    ) : null}
                </section>

                {loading ? (
                    <div className="rounded-[32px] border border-border bg-card p-8 text-center text-muted-foreground shadow-[0_20px_50px_rgba(15,23,42,0.06)]">
                        Checking property availability and matching stays...
                    </div>
                ) : data?.data?.length ? (
                    <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                        {data.data.map((p: PublicProperty) => {
                            const cover = normalizePropertyImage(p);

                            return (
                                <a
                                    key={p.id}
                                    href={
                                        p.slug
                                            ? buildPropertyUrl(p.slug, filters)
                                            : '/properties'
                                    }
                                    className="crystal-card group overflow-hidden p-3 transition duration-200 hover:-translate-y-1"
                                >
                                    <div className="relative h-64 overflow-hidden rounded-[26px] p-3">
                                        <img
                                            src={cover}
                                            alt={p.title}
                                            className="h-full w-full rounded-[22px] object-cover transition duration-300 group-hover:scale-105"
                                        />
                                        <div className="absolute inset-x-6 bottom-6 flex items-center justify-between rounded-full border border-white/40 bg-slate-900/60 px-3 py-1.5 text-[10px] tracking-[0.2em] text-white uppercase backdrop-blur-sm dark:border-white/10">
                                            <span>
                                                {p.status === 'active'
                                                    ? 'Featured'
                                                    : p.status}
                                            </span>
                                            <span>{p.currency || 'USD'}</span>
                                        </div>
                                    </div>

                                    <div className="px-2 pt-4 pb-2">
                                        <div className="flex items-center justify-between gap-3">
                                            <h2 className="text-xl font-semibold tracking-[-0.04em] text-foreground">
                                                {p.title}
                                            </h2>
                                            <span className="text-xs tracking-[0.2em] text-muted-foreground uppercase">
                                                {p.max_guests || 2} guests
                                            </span>
                                        </div>
                                        <p className="mt-2 text-sm leading-6 text-muted-foreground">
                                            {p.address ||
                                                'No address added yet.'}
                                        </p>
                                        <p className="mt-2 text-sm font-medium text-muted-foreground">
                                            {formatRoomLine(p)}
                                        </p>
                                        {filters.checkIn && filters.checkOut ? (
                                            <p className="mt-2 text-xs font-semibold tracking-[0.18em] text-emerald-700 uppercase dark:text-emerald-300">
                                                Available for {filters.checkIn}{' '}
                                                to {filters.checkOut}
                                            </p>
                                        ) : null}
                                        <div className="mt-3 flex items-center justify-between">
                                            <PriceDisplay
                                                amount={Number(
                                                    p.price_per_night ?? 0,
                                                )}
                                                currency={p.currency ?? 'MUR'}
                                                suffix="/ night"
                                                vatStyle="short"
                                                className="text-sm font-medium text-foreground"
                                            />
                                            <span className="text-sm font-medium text-foreground">
                                                View stay →
                                            </span>
                                        </div>
                                        {Array.isArray(p.features) &&
                                        p.features.length > 0 ? (
                                            <div className="mt-3 flex flex-wrap gap-2">
                                                {p.features
                                                    .slice(0, 3)
                                                    .map((feature: string) => (
                                                        <span
                                                            key={feature}
                                                            className="rounded-full border border-border bg-white/60 px-2 py-1 text-[10px] font-medium tracking-[0.16em] text-muted-foreground uppercase dark:bg-white/5"
                                                        >
                                                            {feature}
                                                        </span>
                                                    ))}
                                            </div>
                                        ) : null}
                                    </div>
                                </a>
                            );
                        })}
                    </div>
                ) : (
                    <div className="rounded-[32px] border border-dashed border-border bg-card p-8 text-center text-muted-foreground shadow-[0_20px_50px_rgba(15,23,42,0.06)]">
                        No stays match the current filters. Try broader dates,
                        fewer guests, or a higher max price.
                    </div>
                )}
            </div>
        </div>
    );
}
