import { Head, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import CurrencySwitcher from '@/components/currency-switcher';
import DestinationAutocomplete from '@/components/destination-autocomplete';
import PriceDisplay from '@/components/price-display';
import PublicThemeToggle from '@/components/public-theme-toggle';
import {
    buildPropertiesUrl,
    buildPropertyUrl,
    defaultPropertySearchFilters,
    formatPrice,
    formatRoomLine,
    nextIsoDate,
    normalizePropertyImage,
    todayIsoDate,
    type PropertySearchFilters,
    type PublicProperty,
} from '@/lib/property-search';

/** A house snapped in two, for when no stay has been featured yet.
 *  Sibling to BrokenMapPin on the property page — same broken-outline idiom. */
const BrokenHouse = ({ className }: { className?: string }) => (
    <svg
        viewBox="0 0 48 48"
        fill="currentColor"
        className={className}
        aria-hidden="true"
    >
        {/* roof and wall on one side, ending in the jagged fracture; the
            window is punched out with evenodd so the mark needs no backing */}
        <path
            fillRule="evenodd"
            d="M24 6 L7 21 L11 21 L11 41 L23.4 41 L25.2 35.4 L21.8 30.2 L25.2 24.6 L21.9 19.2 L25.4 13 Z M14.6 26.4 L20.6 26.4 L20.6 32.8 L14.6 32.8 Z"
        />
        {/* the other side, fallen away from the break */}
        <path
            d="M24 6 L25.4 13 L21.9 19.2 L25.2 24.6 L21.8 30.2 L25.2 35.4 L23.4 41 L37 41 L37 21 L41 21 Z"
            transform="translate(3.2 2.4) rotate(7 30 31)"
        />
    </svg>
);

export default function Welcome({
    properties = [],
}: {
    properties?: PublicProperty[];
}) {
    const page = usePage();
    const authUser =
        (page.props as { auth?: { user?: { name?: string } | null } }).auth
            ?.user ?? null;
    const initialBudgetCap = useMemo(() => {
        const highestNightlyRate = Math.max(
            ...(properties ?? []).map((property) =>
                Number(property.price_per_night ?? 0),
            ),
            900,
        );

        return highestNightlyRate;
    }, [properties]);

    const [budgetCap, setBudgetCap] = useState<number>(initialBudgetCap);
    const [searchFilters, setSearchFilters] = useState<PropertySearchFilters>(
        defaultPropertySearchFilters,
    );

    const filteredProperties = useMemo(() => {
        return (properties ?? []).filter(
            (property) => Number(property.price_per_night ?? 0) <= budgetCap,
        );
    }, [properties, budgetCap]);

    const suggestedDestinations = useMemo(() => {
        return Array.from(
            new Set(
                (properties ?? [])
                    .map((property) => property.address || property.title)
                    .filter(Boolean),
            ),
        ).slice(0, 4) as string[];
    }, [properties]);

    const heroProperty = filteredProperties[0] ?? properties[0] ?? null;
    const collectionProperties = filteredProperties.slice(0, 4);

    const activeSearchFilters = useMemo<PropertySearchFilters>(
        () => ({
            ...searchFilters,
            maxPrice: String(budgetCap),
        }),
        [budgetCap, searchFilters],
    );

    const updateSearchFilter = (
        key: keyof PropertySearchFilters,
        value: string,
    ) => {
        setSearchFilters((current) => ({ ...current, [key]: value }));
    };

    const submitSearch = (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        window.location.href = buildPropertiesUrl(activeSearchFilters);
    };

    const todayIso = useMemo(() => todayIsoDate(), []);
    const minCheckOut = searchFilters.checkIn
        ? nextIsoDate(searchFilters.checkIn)
        : nextIsoDate(todayIso);

    const staysHref = buildPropertiesUrl(activeSearchFilters);

    return (
        <>
            <Head title="Stay crystal clear" />

            <div className="relative min-h-screen overflow-hidden px-4 py-6 text-foreground sm:px-6 lg:px-8">
                <div className="pointer-events-none absolute inset-0 opacity-80">
                    <div className="absolute top-12 left-[-10%] h-72 w-72 rounded-full bg-amber-200/35 blur-3xl dark:bg-amber-500/10" />
                    <div className="absolute top-40 right-[-6%] h-80 w-80 rounded-full bg-sky-200/35 blur-3xl dark:bg-sky-500/10" />
                    <div className="absolute bottom-0 left-1/3 h-64 w-64 rounded-full bg-emerald-200/25 blur-3xl dark:bg-emerald-500/10" />
                </div>

                <div className="relative mx-auto max-w-7xl">
                    <header className="glass-panel sticky top-4 z-20 mx-auto flex w-full max-w-6xl items-center justify-between rounded-full border border-white/30 bg-white/80 px-4 py-3 backdrop-blur sm:px-6 dark:border-white/10 dark:bg-slate-900/75">
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
                                    Private stays
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
                                href="#collection"
                                className="transition hover:text-foreground"
                            >
                                Collection
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
                                        className="hidden rounded-full border border-border bg-white/70 px-4 py-2 text-sm font-medium text-foreground transition hover:border-foreground/20 hover:text-foreground sm:inline-flex dark:bg-slate-900/60"
                                    >
                                        Log in
                                    </a>
                                    <a
                                        href="/register"
                                        className="hidden rounded-full border border-border bg-card px-4 py-2 text-sm font-medium text-foreground transition hover:border-foreground/20 sm:inline-flex"
                                    >
                                        Sign up
                                    </a>
                                </>
                            )}
                        </div>
                    </header>

                    <main className="pt-8 sm:pt-10">
                        <section className="grid gap-6 lg:grid-cols-[1.05fr_0.95fr]">
                            <div className="glass-panel relative z-10 rounded-[40px] border border-white/40 bg-white/70 p-6 sm:p-8 lg:p-10 dark:border-white/10 dark:bg-slate-900/60">
                                <div className="inline-flex items-center gap-2 rounded-full border border-white/50 bg-white/35 px-3 py-1.5 text-[11px] font-medium tracking-[0.22em] text-muted-foreground uppercase dark:border-white/10 dark:bg-white/5">
                                    <span className="h-2 w-2 rounded-full bg-emerald-400" />
                                    Simple search, clear stays
                                </div>

                                <h1 className="mt-5 max-w-3xl text-4xl font-semibold tracking-[-0.07em] text-foreground sm:text-5xl lg:text-6xl">
                                    Find the right stay in a few clicks.
                                </h1>

                                <p className="mt-5 max-w-2xl text-base leading-8 text-muted-foreground sm:text-lg">
                                    Search by destination, dates, guests, and
                                    budget. Then move straight into stays that
                                    match.
                                </p>

                                {/* No overflow-hidden here: it would clip the
                                    destination suggestions that drop below. */}
                                <form
                                    onSubmit={submitSearch}
                                    className="relative z-30 mt-8 rounded-[32px] border border-white/60 bg-white/85 shadow-[0_22px_70px_rgba(15,23,42,0.08)] backdrop-blur dark:border-white/10 dark:bg-slate-900/75"
                                >
                                    <div className="grid gap-0 xl:grid-cols-[1.35fr_0.9fr_0.9fr_0.7fr_auto]">
                                        <label className="border-b border-border px-5 py-4 text-left xl:border-r xl:border-b-0">
                                            <span className="block text-[11px] font-semibold tracking-[0.2em] text-muted-foreground uppercase">
                                                Where
                                            </span>
                                            <DestinationAutocomplete
                                                value={searchFilters.q}
                                                onChange={(value) =>
                                                    updateSearchFilter(
                                                        'q',
                                                        value,
                                                    )
                                                }
                                                availableProperties={
                                                    properties ?? []
                                                }
                                                placeholder="Title, city, or address"
                                                inputClassName="mt-2 w-full border-0 bg-transparent p-0 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-0"
                                            />
                                        </label>
                                        <label className="border-b border-border px-5 py-4 text-left xl:border-r xl:border-b-0">
                                            <span className="block text-[11px] font-semibold tracking-[0.2em] text-muted-foreground uppercase">
                                                Check in
                                            </span>
                                            <input
                                                type="date"
                                                min={todayIso}
                                                value={searchFilters.checkIn}
                                                onChange={(event) =>
                                                    updateSearchFilter(
                                                        'checkIn',
                                                        event.target.value,
                                                    )
                                                }
                                                className="mt-2 w-full border-0 bg-transparent p-0 text-sm text-foreground focus:ring-0 focus:outline-none"
                                            />
                                        </label>
                                        <label className="border-b border-border px-5 py-4 text-left xl:border-r xl:border-b-0">
                                            <span className="block text-[11px] font-semibold tracking-[0.2em] text-muted-foreground uppercase">
                                                Check out
                                            </span>
                                            <input
                                                type="date"
                                                min={minCheckOut}
                                                value={searchFilters.checkOut}
                                                onChange={(event) =>
                                                    updateSearchFilter(
                                                        'checkOut',
                                                        event.target.value,
                                                    )
                                                }
                                                className="mt-2 w-full border-0 bg-transparent p-0 text-sm text-foreground focus:ring-0 focus:outline-none"
                                            />
                                        </label>
                                        <label className="px-5 py-4 text-left xl:border-r xl:border-border">
                                            <span className="block text-[11px] font-semibold tracking-[0.2em] text-muted-foreground uppercase">
                                                Guests
                                            </span>
                                            <input
                                                type="number"
                                                min={1}
                                                value={searchFilters.guests}
                                                onChange={(event) =>
                                                    updateSearchFilter(
                                                        'guests',
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder="Guests"
                                                className="mt-2 w-full border-0 bg-transparent p-0 text-sm text-foreground placeholder:text-muted-foreground focus:ring-0 focus:outline-none"
                                            />
                                        </label>
                                        <div className="flex items-center justify-center px-4 py-4">
                                            <button
                                                type="submit"
                                                className="crystal-button w-full justify-center bg-primary text-primary-foreground hover:bg-primary/90 xl:w-auto"
                                            >
                                                Search
                                            </button>
                                        </div>
                                    </div>
                                </form>

                                {suggestedDestinations.length ? (
                                    <div className="mt-5 flex flex-wrap items-center gap-2">
                                        {suggestedDestinations.map(
                                            (destination) => (
                                                <button
                                                    key={destination}
                                                    type="button"
                                                    onClick={() =>
                                                        updateSearchFilter(
                                                            'q',
                                                            destination,
                                                        )
                                                    }
                                                    className="rounded-full border border-white/60 bg-white/50 px-3 py-1.5 text-xs font-medium text-foreground transition hover:border-foreground/20 hover:text-foreground dark:border-white/10 dark:bg-white/5"
                                                >
                                                    {destination}
                                                </button>
                                            ),
                                        )}
                                    </div>
                                ) : null}

                                <div className="mt-8">
                                    <label className="mb-2 block text-xs font-semibold tracking-[0.2em] text-muted-foreground uppercase">
                                        Budget cap
                                    </label>
                                    <input
                                        type="range"
                                        min={120}
                                        max={Math.max(900, initialBudgetCap)}
                                        step={10}
                                        value={budgetCap}
                                        onChange={(event) =>
                                            setBudgetCap(
                                                Number(event.target.value),
                                            )
                                        }
                                        className="w-full accent-slate-900"
                                    />
                                    <div className="mt-3 flex items-center justify-between text-sm text-muted-foreground">
                                        <span>
                                            {collectionProperties.length} stays
                                            match
                                        </span>
                                        <span className="font-semibold text-foreground">
                                            Up to{' '}
                                            {formatPrice(
                                                budgetCap,
                                                heroProperty?.currency ?? 'USD',
                                            )}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div className="crystal-card relative overflow-hidden border border-white/40 bg-white/70 p-4 sm:p-5 dark:border-white/10 dark:bg-slate-900/60">
                                <div className="absolute inset-0 bg-[linear-gradient(150deg,rgba(255,255,255,0.78),rgba(255,255,255,0.18)_48%,rgba(15,23,42,0.35)_100%)]" />
                                {heroProperty ? (
                                    <div className="relative overflow-hidden rounded-[30px]">
                                        <img
                                            src={normalizePropertyImage(
                                                heroProperty,
                                            )}
                                            alt={
                                                heroProperty.title ||
                                                'Featured property'
                                            }
                                            className="h-[520px] w-full object-cover"
                                        />
                                        <div className="absolute inset-x-0 bottom-0 p-5">
                                            <div className="rounded-[28px] border border-white/30 bg-slate-950/40 p-5 text-white backdrop-blur-md dark:border-white/10">
                                                <div className="flex items-center justify-between gap-3">
                                                    <span className="rounded-full bg-white/15 px-3 py-1 text-[10px] font-semibold tracking-[0.2em] text-white/85 uppercase dark:bg-white/10">
                                                        Featured stay
                                                    </span>
                                                    <span className="text-sm font-medium text-white/80">
                                                        {heroProperty.currency ||
                                                            'USD'}
                                                    </span>
                                                </div>
                                                <h2 className="mt-4 text-2xl font-semibold tracking-[-0.05em] text-white sm:text-3xl">
                                                    {heroProperty.title}
                                                </h2>
                                                {heroProperty.address ? (
                                                    <p className="mt-2 text-sm leading-6 text-white/80">
                                                        {heroProperty.address}
                                                    </p>
                                                ) : null}
                                                <div className="mt-4 flex items-center justify-between gap-4">
                                                    <div>
                                                        <div className="text-sm text-white/70">
                                                            {formatRoomLine(
                                                                heroProperty,
                                                            )}
                                                        </div>
                                                        <div className="mt-1 text-xl font-semibold text-white">
                                                            {formatPrice(
                                                                heroProperty.price_per_night,
                                                                heroProperty.currency ??
                                                                    'USD',
                                                            )}
                                                            <span className="text-sm font-medium text-white/70">
                                                                {' '}
                                                                / night
                                                            </span>
                                                        </div>
                                                    </div>
                                                    <a
                                                        href={
                                                            heroProperty.slug
                                                                ? buildPropertyUrl(
                                                                      heroProperty.slug,
                                                                      activeSearchFilters,
                                                                  )
                                                                : staysHref
                                                        }
                                                        className="rounded-full bg-card px-4 py-2 text-sm font-semibold text-foreground transition hover:bg-accent"
                                                    >
                                                        View stay
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                ) : (
                                    <div className="relative flex h-[520px] flex-col items-center justify-center overflow-hidden rounded-[30px] bg-muted px-6 py-10 text-center">
                                        {/* faint grid, so the empty state still reads as a card */}
                                        <div
                                            aria-hidden="true"
                                            className="pointer-events-none absolute inset-0 [background-image:linear-gradient(to_right,var(--color-border)_1px,transparent_1px),linear-gradient(to_bottom,var(--color-border)_1px,transparent_1px)] [background-size:32px_32px] opacity-[0.35]"
                                        />

                                        <div className="relative flex h-20 w-20 items-center justify-center rounded-full border border-amber-200 bg-card shadow-sm dark:border-amber-900/60">
                                            <BrokenHouse className="h-11 w-11 text-amber-600 dark:text-amber-400" />
                                        </div>

                                        <h2 className="relative mt-6 text-xl font-semibold text-foreground">
                                            No featured property yet
                                        </h2>
                                        <p className="relative mt-3 max-w-md text-sm leading-7 text-muted-foreground">
                                            Once a stay is published it will be
                                            introduced here, with its cover
                                            photo and nightly rate.
                                        </p>

                                        <div className="relative mt-6 rounded-full border border-border bg-card px-4 py-2 text-sm font-medium text-foreground shadow-sm">
                                            Featured stay pending
                                        </div>
                                    </div>
                                )}
                            </div>
                        </section>

                        <section
                            id="collection"
                            className="glass-panel mt-10 rounded-[34px] border border-white/40 bg-white/70 p-6 sm:p-7 dark:border-white/10 dark:bg-slate-900/60"
                        >
                            <div className="flex items-end justify-between gap-4">
                                <div>
                                    <div className="text-xs tracking-[0.24em] text-muted-foreground uppercase">
                                        Collection
                                    </div>
                                    <h2 className="mt-3 text-3xl font-semibold tracking-[-0.05em] text-foreground">
                                        Simple stays, clear details.
                                    </h2>
                                </div>
                                <a
                                    href={staysHref}
                                    className="hidden rounded-full border border-border bg-card px-4 py-2 text-sm font-medium text-foreground transition hover:border-foreground/20 hover:text-foreground sm:inline-flex"
                                >
                                    Open all stays
                                </a>
                            </div>

                            <div className="mt-6 grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
                                {collectionProperties.length ? (
                                    collectionProperties.map(
                                        (property, index) => (
                                            <a
                                                key={
                                                    property.id ??
                                                    property.slug ??
                                                    index
                                                }
                                                href={
                                                    property.slug
                                                        ? buildPropertyUrl(
                                                              property.slug,
                                                              activeSearchFilters,
                                                          )
                                                        : staysHref
                                                }
                                                className="group rounded-[30px] border border-white/60 bg-white/55 p-3 shadow-[0_18px_50px_rgba(15,23,42,0.06)] transition hover:-translate-y-1 hover:bg-white/70 dark:border-white/10 dark:bg-white/5 dark:hover:bg-white/10"
                                            >
                                                <div className="relative h-56 overflow-hidden rounded-[24px]">
                                                    <img
                                                        src={normalizePropertyImage(
                                                            property,
                                                        )}
                                                        alt={
                                                            property.title ||
                                                            'Property'
                                                        }
                                                        className="h-full w-full object-cover transition duration-300 group-hover:scale-105"
                                                    />
                                                    <div className="absolute inset-x-4 bottom-4 flex items-center justify-between rounded-full border border-white/35 bg-slate-900/65 px-3 py-1.5 text-[10px] tracking-[0.2em] text-white uppercase backdrop-blur-sm dark:border-white/10">
                                                        <span>
                                                            {property.currency ||
                                                                'USD'}
                                                        </span>
                                                        <span>
                                                            {property.max_guests ||
                                                                2}{' '}
                                                            guests
                                                        </span>
                                                    </div>
                                                </div>
                                                <div className="px-2 pt-4 pb-2">
                                                    <div className="text-lg font-semibold tracking-[-0.04em] text-foreground">
                                                        {property.title ||
                                                            'Untitled property'}
                                                    </div>
                                                    <div className="mt-1 text-sm text-muted-foreground">
                                                        {property.address ||
                                                            'Address not added yet.'}
                                                    </div>
                                                    <div className="mt-3 text-sm font-medium text-muted-foreground">
                                                        {formatRoomLine(
                                                            property,
                                                        )}
                                                    </div>
                                                    <div className="mt-4 flex items-center justify-between gap-2">
                                                        <PriceDisplay
                                                            amount={Number(
                                                                property.price_per_night ??
                                                                    0,
                                                            )}
                                                            currency={
                                                                property.currency ??
                                                                'MUR'
                                                            }
                                                            vatStyle="short"
                                                            className="text-base font-semibold text-foreground"
                                                        />
                                                        <span className="text-sm font-semibold text-foreground">
                                                            View {'>'}
                                                        </span>
                                                    </div>
                                                </div>
                                            </a>
                                        ),
                                    )
                                ) : (
                                    <div className="col-span-full rounded-[28px] border border-dashed border-border bg-white/40 p-8 text-center text-muted-foreground dark:bg-white/5">
                                        No homepage cards match the current
                                        budget yet. Increase the cap to show
                                        more stays.
                                    </div>
                                )}
                            </div>
                        </section>
                    </main>
                </div>
            </div>
        </>
    );
}
