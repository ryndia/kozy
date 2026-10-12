import { usePage } from '@inertiajs/react';
import React, { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import CurrencySwitcher from '@/components/currency-switcher';
import PriceDisplay from '@/components/price-display';
import PropertyPolicies from '@/components/property-policies';
import PublicThemeToggle from '@/components/public-theme-toggle';
import { apiGet, apiPost, extractErrorMessage } from '@/lib/api';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    buildPropertiesUrl,
    buildPropertyUrl,
    defaultPropertySearchFilters,
    formatPrice,
    normalizePropertyImage,
    normalizePropertyImages,
    readPropertySearchFilters,
    type PropertyImageItem,
    type PropertyReviewItem,
    type PropertySearchFilters,
    type PublicProperty,
} from '@/lib/property-search';

type AvailabilityBlock = {
    from: string;
    to: string;
};

type AvailabilityResponse = {
    blocked: AvailabilityBlock[];
};

type AuthenticatedUser = {
    id: number;
    name: string;
    email: string;
};

type ReservationResult = {
    booking?: {
        id: number;
        check_in?: string;
        check_out?: string;
        total_price?: number;
        guests?: number;
    };
    payment?: {
        id?: number;
        amount?: number;
        status?: string;
    };
    redirect_url?: string;
};

const FEATURE_GROUPS = {
    Essentials: [
        'WiFi',
        'Internet',
        'Kitchen',
        'Air Conditioning',
        'Heating',
        'Bathroom',
        'Toilet',
        'Shower',
        'Essentials',
    ],
    Comfort: [
        'Bed',
        'Towels',
        'Soap',
        'Shampoo',
        'Hair Dryer',
        'Iron',
        'TV',
        'Desk',
        'Laundry',
        'Dryer',
        'Washer',
    ],
    'Outside & Leisure': [
        'Pool',
        'Garden',
        'Balcony',
        'Terrace',
        'Patio',
        'Hot Tub',
        'Sauna',
        'Gym',
        'Parking',
    ],
    'Family & Safety': [
        'Pets Allowed',
        'No Smoking',
        'Security',
        'Doorman',
        'Concierge',
        'Elevator',
        'Baby Crib',
        'Maid Service',
    ],
    Dining: ['Breakfast', 'Lunch', 'Dinner'],
} as const;

const FEATURE_EMOJIS: Record<string, string> = {
    WiFi: '📶',
    Internet: '📶',
    Kitchen: '🍳',
    'Air Conditioning': '❄️',
    Heating: '🔥',
    Bathroom: '🚿',
    Toilet: '🚽',
    Shower: '🚿',
    Essentials: '🎒',
    Bed: '🛏️',
    Towels: '🧻',
    Soap: '🧼',
    Shampoo: '💆',
    'Hair Dryer': '💇',
    Iron: '👔',
    TV: '📺',
    Desk: '💼',
    Laundry: '🧺',
    Dryer: '🧺',
    Washer: '🧺',
    Pool: '🏊',
    Garden: '🌳',
    Balcony: '🏠',
    Terrace: '🌂',
    Patio: '🌂',
    'Hot Tub': '♨️',
    Sauna: '♨️',
    Gym: '💪',
    Parking: '🚗',
    'Pets Allowed': '🐾',
    'No Smoking': '🚭',
    Security: '🔒',
    Doorman: '🚪',
    Concierge: '🔔',
    Elevator: '🛗',
    'Baby Crib': '👶',
    'Maid Service': '🧹',
    Breakfast: '🍳',
    Lunch: '🍽️',
    Dinner: '🍽️',
};

const getFeatureEmoji = (feature: string) => FEATURE_EMOJIS[feature] ?? '🏷️';

/**
 * Coordinates come back as strings, nulls or empty strings, and `Number(null)`
 * is 0 - which passes `Number.isFinite` and would point the map at Null Island
 * instead of admitting the stay has no location.
 */
const parseCoordinate = (value: unknown, limit: number): number | null => {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const parsed = Number(value);

    if (!Number.isFinite(parsed) || Math.abs(parsed) > limit) {
        return null;
    }

    return parsed;
};

/** A map pin snapped in two, for stays with no coordinates on file. */
const BrokenMapPin = ({ className }: { className?: string }) => (
    <svg
        viewBox="0 0 48 48"
        fill="currentColor"
        className={className}
        aria-hidden="true"
    >
        {/* head and shoulders, ending in the jagged fracture; the inner dot is
            punched out with evenodd so the mark needs no backing colour */}
        <path
            fillRule="evenodd"
            d="M17.4 29 C14.6 24.4 12.4 19.6 12.4 16.1 C12.4 9.7 17.6 4.5 24 4.5 C30.4 4.5 35.6 9.7 35.6 16.1 C35.6 19.6 33.4 24.4 30.6 29 L27.4 31.4 L24 28.8 L20.6 31.6 Z M28.2 16 A4.2 4.2 0 1 1 19.8 16 A4.2 4.2 0 1 1 28.2 16 Z"
        />
        {/* the tip, snapped off and fallen away from the break */}
        <path
            d="M17.4 29 L20.6 31.6 L24 28.8 L27.4 31.4 L30.6 29 C29 33 24 41.5 24 41.5 C24 41.5 19 33 17.4 29 Z"
            transform="translate(3.4 3.6) rotate(16 24 36)"
        />
    </svg>
);

const buildOpenStreetMapEmbed = (lat: number, lng: number) => {
    const delta = 0.02;
    const bbox = `${lng - delta},${lat - delta},${lng + delta},${lat + delta}`;
    return `https://www.openstreetmap.org/export/embed.html?bbox=${encodeURIComponent(bbox)}&layer=mapnik&marker=${lat}%2C${lng}`;
};

const ROOM_TYPE_LABELS: Record<string, string> = {
    bedroom: 'Bedrooms',
    kitchen: 'Kitchen',
    bathroom: 'Bathroom',
    'living-room': 'Living room',
    'dining-room': 'Dining room',
    workspace: 'Workspace',
    exterior: 'Exterior',
    amenity: 'Amenities',
    other: 'Other rooms',
};

const weekdayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

const padDatePart = (value: number) => String(value).padStart(2, '0');

const formatTimeValue = (value: string | null | undefined) => {
    if (!value) {
        return null;
    }

    const [hours = '00', minutes = '00', seconds = '00'] = value.split(':');
    const hourNumber = Number(hours);
    const safeMinutes = Number(minutes) || 0;
    const safeSeconds = Number(seconds) || 0;

    const date = new Date();
    date.setHours(hourNumber, safeMinutes, safeSeconds, 0);

    return new Intl.DateTimeFormat(undefined, {
        hour: 'numeric',
        minute: '2-digit',
    }).format(date);
};

const toIsoDate = (date: Date) =>
    `${date.getFullYear()}-${padDatePart(date.getMonth() + 1)}-${padDatePart(date.getDate())}`;

const fromIsoDate = (value: string) => {
    const [year, month, day] = value.split('-').map(Number);
    return new Date(year, month - 1, day);
};

const startOfMonth = (date: Date) =>
    new Date(date.getFullYear(), date.getMonth(), 1);

const addMonths = (date: Date, amount: number) =>
    new Date(date.getFullYear(), date.getMonth() + amount, 1);

const buildCalendarDays = (month: Date) => {
    const monthStart = startOfMonth(month);
    const daysInMonth = new Date(
        month.getFullYear(),
        month.getMonth() + 1,
        0,
    ).getDate();
    const leadingEmptyDays = monthStart.getDay();
    const cells: Array<Date | null> = [];

    for (let index = 0; index < leadingEmptyDays; index += 1) {
        cells.push(null);
    }

    for (let day = 1; day <= daysInMonth; day += 1) {
        cells.push(new Date(month.getFullYear(), month.getMonth(), day));
    }

    while (cells.length % 7 !== 0) {
        cells.push(null);
    }

    return cells;
};

const isDateBlocked = (isoDate: string, blockedRanges: AvailabilityBlock[]) => {
    return blockedRanges.some(
        (range) => isoDate >= range.from && isoDate < range.to,
    );
};

const doesSelectionOverlap = (
    checkIn: string,
    checkOut: string,
    blockedRanges: AvailabilityBlock[],
) => {
    return blockedRanges.some(
        (range) => range.from < checkOut && range.to > checkIn,
    );
};

const countNights = (checkIn: string, checkOut: string) => {
    const start = fromIsoDate(checkIn).getTime();
    const end = fromIsoDate(checkOut).getTime();

    return Math.max(0, Math.round((end - start) / 86400000));
};

const addDays = (isoDate: string, amount: number) => {
    const date = fromIsoDate(isoDate);
    date.setDate(date.getDate() + amount);

    return toIsoDate(date);
};

/** Stale dates arrive from bookmarked URLs, so drop anything already past. */
const dropPastDate = (isoDate: string, todayIso: string) =>
    isoDate && isoDate >= todayIso ? isoDate : '';

export default function PropertyShow({ slug: initialSlug }: { slug?: string }) {
    const page = usePage();
    const authUser =
        (page.props as { auth?: { user?: AuthenticatedUser | null } }).auth
            ?.user ?? null;
    const [slug, setSlug] = useState<string | null>(initialSlug || null);
    const [property, setProperty] = useState<PublicProperty | null>(null);
    const [availability, setAvailability] =
        useState<AvailabilityResponse | null>(null);
    const [mapUrl, setMapUrl] = useState<string | null>(null);
    const [selectedCheckIn, setSelectedCheckIn] = useState('');
    const [selectedCheckOut, setSelectedCheckOut] = useState('');
    const [selectedGuests, setSelectedGuests] = useState('1');
    const [searchFilters, setSearchFilters] = useState<PropertySearchFilters>(
        defaultPropertySearchFilters,
    );
    const [calendarMonth, setCalendarMonth] = useState(() =>
        startOfMonth(new Date()),
    );
    const [galleryOpen, setGalleryOpen] = useState(false);
    const [galleryIndex, setGalleryIndex] = useState(0);
    const [currentUser, setCurrentUser] = useState<AuthenticatedUser | null>(
        authUser,
    );
    const [reservationDialogOpen, setReservationDialogOpen] = useState(false);
    const [reservationStep, setReservationStep] = useState<
        'account' | 'login' | 'success'
    >('account');
    const [reservationLoading, setReservationLoading] = useState(false);
    const [reservationOtpEmail, setReservationOtpEmail] = useState('');
    const [reservationError, setReservationError] = useState<string | null>(
        null,
    );
    const [reservationResult, setReservationResult] =
        useState<ReservationResult | null>(null);
    const [reservationAccount, setReservationAccount] = useState({
        name: '',
        email: '',
        password: '',
    });

    useEffect(() => {
        // Use prop if available, otherwise extract from URL
        if (initialSlug) {
            setSlug(initialSlug);
            return;
        }

        const parts = window.location.pathname.split('/').filter(Boolean);
        const nextSlug = parts[parts.length - 1] || null;
        setSlug(nextSlug);
    }, [initialSlug]);

    useEffect(() => {
        const nextFilters = readPropertySearchFilters(window.location.search);
        const today = toIsoDate(new Date());
        const checkIn = dropPastDate(nextFilters.checkIn, today);

        setSearchFilters(nextFilters);
        setSelectedCheckIn(checkIn);
        setSelectedCheckOut(
            checkIn
                ? dropPastDate(nextFilters.checkOut, addDays(checkIn, 1))
                : '',
        );
        setSelectedGuests(nextFilters.guests || '1');
    }, []);

    useEffect(() => {
        setCurrentUser(authUser);
    }, [authUser]);

    useEffect(() => {
        if (!slug) return;

        apiGet(`/api/properties/${slug}`).then((res) =>
            setProperty(res.data || res),
        );
        apiGet(`/api/properties/${slug}/availability`)
            .then(setAvailability)
            .catch(() => setAvailability(null));
    }, [slug]);

    useEffect(() => {
        if (!slug) {
            return;
        }

        window.history.replaceState(
            {},
            '',
            buildPropertyUrl(slug, {
                ...searchFilters,
                checkIn: selectedCheckIn,
                checkOut: selectedCheckOut,
                guests: selectedGuests,
            }),
        );
    }, [
        searchFilters,
        selectedCheckIn,
        selectedCheckOut,
        selectedGuests,
        slug,
    ]);

    useEffect(() => {
        if (!property) {
            setMapUrl(null);
            return;
        }

        const lat = parseCoordinate(property.lat, 90);
        const lng = parseCoordinate(property.lng, 180);
        // Exactly (0, 0) is open ocean, so treat that pair as "not set".
        const hasLocation =
            lat !== null && lng !== null && !(lat === 0 && lng === 0);

        setMapUrl(hasLocation ? buildOpenStreetMapEmbed(lat, lng) : null);
    }, [property]);

    const approximateLocation = useMemo(() => {
        if (!property) return 'Loading location…';

        return property.address || 'Private coastal hideaway';
    }, [property]);

    const groupedFeatures = useMemo(() => {
        if (!property?.features || !Array.isArray(property.features)) {
            return [];
        }

        const selected = new Set(
            property.features.map((feature: string) => feature.trim()),
        );

        return Object.entries(FEATURE_GROUPS)
            .map(([category, names]) => {
                const items = names
                    .filter((name) => selected.has(name))
                    .map((name) => ({ name, emoji: getFeatureEmoji(name) }));

                return { category, items };
            })
            .filter((group) => group.items.length > 0);
    }, [property]);

    const propertyImages: PropertyImageItem[] = useMemo(
        () => normalizePropertyImages(property),
        [property],
    );

    const selectedGalleryImage =
        propertyImages[galleryIndex] ?? propertyImages[0] ?? null;

    const openGallery = (index: number) => {
        if (!propertyImages.length) {
            return;
        }

        setGalleryIndex(index);
        setGalleryOpen(true);
    };

    const showPreviousGalleryImage = () => {
        if (!propertyImages.length) {
            return;
        }

        setGalleryIndex(
            (current) =>
                (current - 1 + propertyImages.length) % propertyImages.length,
        );
    };

    const showNextGalleryImage = () => {
        if (!propertyImages.length) {
            return;
        }

        setGalleryIndex((current) => (current + 1) % propertyImages.length);
    };

    const getGalleryIndex = (image: PropertyImageItem) => {
        const index = propertyImages.findIndex(
            (candidate) => candidate.url === image.url,
        );

        return index >= 0 ? index : 0;
    };

    const groupedRoomPhotos: Record<string, PropertyImageItem[]> =
        useMemo(() => {
            return propertyImages.reduce(
                (
                    groups: Record<string, PropertyImageItem[]>,
                    image: PropertyImageItem,
                ) => {
                    const roomType = image.room_type || 'other';

                    if (!groups[roomType]) {
                        groups[roomType] = [];
                    }

                    groups[roomType].push(image);

                    return groups;
                },
                {},
            );
        }, [propertyImages]);

    const amenities = useMemo(() => {
        return Array.isArray(property?.amenities)
            ? property.amenities.filter(
                  (item): item is string =>
                      typeof item === 'string' && item.trim().length > 0,
              )
            : [];
    }, [property]);

    const propertyReviews: PropertyReviewItem[] = useMemo(() => {
        return Array.isArray(property?.reviews) ? property.reviews : [];
    }, [property]);

    const todayIso = useMemo(() => toIsoDate(new Date()), []);
    const currentMonthStart = useMemo(() => startOfMonth(new Date()), []);
    const blockedRanges = availability?.blocked ?? [];
    const selectedNightCount =
        selectedCheckIn && selectedCheckOut
            ? countNights(selectedCheckIn, selectedCheckOut)
            : 0;
    const totalStayPrice =
        selectedNightCount > 0
            ? selectedNightCount * Number(property?.price_per_night ?? 0)
            : 0;
    const selectedRangeAvailable = useMemo(() => {
        if (!selectedCheckIn || !selectedCheckOut) {
            return null;
        }

        if (selectedCheckIn < todayIso || selectedCheckOut <= selectedCheckIn) {
            return false;
        }

        return !doesSelectionOverlap(
            selectedCheckIn,
            selectedCheckOut,
            blockedRanges,
        );
    }, [blockedRanges, selectedCheckIn, selectedCheckOut, todayIso]);

    // Check-out always has to land after check-in, and never before tomorrow.
    const minCheckOut = selectedCheckIn
        ? addDays(selectedCheckIn, 1)
        : addDays(todayIso, 1);

    const handleCheckInChange = (value: string) => {
        const nextCheckIn = value && value < todayIso ? todayIso : value;
        setSelectedCheckIn(nextCheckIn);

        if (
            nextCheckIn &&
            selectedCheckOut &&
            selectedCheckOut <= nextCheckIn
        ) {
            setSelectedCheckOut(addDays(nextCheckIn, 1));
        }
    };

    const backToResultsHref = useMemo(() => {
        return buildPropertiesUrl(searchFilters);
    }, [searchFilters]);

    const calendarMonths = useMemo(() => {
        return [calendarMonth, addMonths(calendarMonth, 1)];
    }, [calendarMonth]);

    const bedroomsLabel = property?.bedrooms ?? 'Not set';
    const kitchensLabel = property?.kitchens ?? 'Not set';
    const bathroomsLabel = property?.bathrooms ?? 'Not set';
    const checkInLabel = formatTimeValue(property?.check_in) ?? 'Not set yet';
    const checkOutLabel = formatTimeValue(property?.check_out) ?? 'Not set yet';
    const locationLabel = property?.address
        ? property.address
        : 'Location not set yet';

    const bookingPayload = property?.id
        ? {
              property_id: property.id,
              check_in: selectedCheckIn,
              check_out: selectedCheckOut,
              guests: Number(selectedGuests || 1),
          }
        : null;

    const resetReservationFlow = () => {
        setReservationDialogOpen(false);
        setReservationStep('account');
        setReservationOtpEmail('');
        setReservationError(null);
        setReservationResult(null);
        setReservationAccount((current) => ({ ...current, password: '' }));
    };

    const handleReserveAsSignedInUser = async () => {
        if (!bookingPayload) {
            return;
        }

        setReservationLoading(true);
        setReservationError(null);

        try {
            const response = await apiPost('/api/bookings', bookingPayload);

            setReservationResult({
                booking: response.booking,
                payment: response.payment,
                redirect_url: '/client/bookings',
            });
            setReservationStep('success');
            setReservationDialogOpen(true);
            toast.success(
                'Reservation created. You can review payment and receipt details in your booking history.',
            );
        } catch (error) {
            const message = extractErrorMessage(error);
            setReservationError(message);
            toast.error(message);
        } finally {
            setReservationLoading(false);
        }
    };

    const handleStartGuestReservation = async () => {
        if (!bookingPayload) {
            return;
        }

        setReservationLoading(true);
        setReservationError(null);

        try {
            const response = await apiPost('/client/auth/start', {
                name: reservationAccount.name,
                email: reservationAccount.email,
                intended: '/client/bookings',
                reservation: bookingPayload,
            });

            setReservationOtpEmail(response.email ?? reservationAccount.email);

            // The email already belongs to an account, so we authenticate
            // instead of signing up and reserve right after the password check.
            if (response.account_exists) {
                setReservationStep('login');
                toast.info(
                    response.message ??
                        'This email already has an account. Enter your password to continue.',
                );
                return;
            }

            // The account and booking now exist: straight on to paying for
            // the room. The password comes after the payment.
            window.location.href = response.redirect_url || '/client/bookings';
        } catch (error) {
            const message = extractErrorMessage(error);
            setReservationError(message);
            toast.error(message);
        } finally {
            setReservationLoading(false);
        }
    };

    const handleLoginAndReserve = async () => {
        if (!bookingPayload) {
            return;
        }

        setReservationLoading(true);
        setReservationError(null);

        try {
            const response = await apiPost('/client/auth/login', {
                email: reservationAccount.email,
                password: reservationAccount.password,
                intended: '/client/bookings',
                reservation: bookingPayload,
            });
            window.location.href = response.redirect_url || '/client/bookings';
        } catch (error) {
            const message = extractErrorMessage(error);
            setReservationError(message);
            toast.error(message);
        } finally {
            setReservationLoading(false);
        }
    };

    const handleReserveClick = async () => {
        if (selectedRangeAvailable !== true || !property?.id) {
            return;
        }

        if (currentUser) {
            await handleReserveAsSignedInUser();
            return;
        }

        setReservationStep('account');
        setReservationDialogOpen(true);
        setReservationError(null);
    };

    if (!property) {
        return (
            <div className="p-8 text-center text-muted-foreground">
                Loading stay details…
            </div>
        );
    }

    return (
        <div className="min-h-screen px-4 py-6 text-foreground sm:px-6 lg:px-8">
            <div className="mx-auto max-w-7xl">
                <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                    <div className="flex flex-wrap items-center gap-3">
                        <a
                            href={backToResultsHref}
                            className="inline-flex items-center gap-2 rounded-full border border-border bg-card px-4 py-2 text-sm font-medium text-foreground shadow-sm transition hover:border-foreground/20 hover:text-foreground"
                        >
                            ← Back to stays
                        </a>
                        <div className="rounded-full border border-border bg-card px-4 py-2 text-sm font-medium text-foreground shadow-sm">
                            {property.currency || 'USD'} listing
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-3">
                        <CurrencySwitcher />
                        <PublicThemeToggle />
                        {currentUser ? (
                            <a
                                href="/client/bookings"
                                className="inline-flex rounded-full border border-border bg-card px-4 py-2 text-sm font-medium text-foreground shadow-sm transition hover:border-foreground/20 hover:text-foreground"
                            >
                                My bookings
                            </a>
                        ) : (
                            <>
                                <a
                                    href="/login"
                                    className="inline-flex rounded-full border border-border bg-card px-4 py-2 text-sm font-medium text-foreground shadow-sm transition hover:border-foreground/20 hover:text-foreground"
                                >
                                    Log in
                                </a>
                                <a
                                    href="/register"
                                    className="inline-flex rounded-full border border-border bg-primary px-4 py-2 text-sm font-medium text-primary-foreground shadow-sm transition hover:bg-primary/90"
                                >
                                    Sign up
                                </a>
                            </>
                        )}
                    </div>
                </div>

                <div className="mb-8 grid gap-5 lg:grid-cols-[1.35fr_0.65fr]">
                    <div className="space-y-4">
                        <button
                            type="button"
                            onClick={() => openGallery(0)}
                            className="block w-full overflow-hidden rounded-[32px] border border-white/60 bg-card p-3 text-left shadow-[0_20px_60px_rgba(15,23,42,0.08)] transition hover:opacity-95 dark:border-white/10"
                        >
                            <div className="overflow-hidden rounded-[26px] bg-muted">
                                <img
                                    src={normalizePropertyImage(property)}
                                    alt={
                                        propertyImages[0]?.alt_text ||
                                        property.title
                                    }
                                    className="h-[430px] w-full object-cover"
                                />
                            </div>
                        </button>

                        {propertyImages.length > 1 ? (
                            <div className="grid grid-cols-3 gap-3">
                                {propertyImages
                                    .slice(1, 4)
                                    .map(
                                        (
                                            image: PropertyImageItem,
                                            index: number,
                                        ) => (
                                            <button
                                                key={`${image.url}-${index}`}
                                                type="button"
                                                onClick={() =>
                                                    openGallery(index + 1)
                                                }
                                                className="overflow-hidden rounded-[22px] border border-white/60 bg-card p-1 text-left shadow-sm transition hover:opacity-95 dark:border-white/10"
                                            >
                                                <div className="rounded-[18px] bg-muted">
                                                    <img
                                                        src={image.url}
                                                        alt={
                                                            image.alt_text ||
                                                            image.room_name ||
                                                            `${property.title} view ${index + 2}`
                                                        }
                                                        className="h-28 w-full rounded-[18px] object-cover"
                                                    />
                                                </div>
                                            </button>
                                        ),
                                    )}
                            </div>
                        ) : null}

                        <section className="rounded-[32px] border border-border bg-card p-6 shadow-[0_20px_60px_rgba(15,23,42,0.06)]">
                            <div className="text-xs tracking-[0.22em] text-muted-foreground uppercase">
                                About this stay
                            </div>
                            {property.description ? (
                                <div
                                    className="prose prose-p:leading-8 mt-4 max-w-none text-foreground"
                                    dangerouslySetInnerHTML={{
                                        __html: property.description,
                                    }}
                                />
                            ) : (
                                <p className="mt-4 text-base leading-8 text-foreground">
                                    A quiet, elevated escape with premium
                                    finishes and a seamless guest experience.
                                </p>
                            )}
                        </section>
                    </div>

                    <aside className="rounded-[32px] border border-border bg-card p-6 shadow-[0_20px_60px_rgba(15,23,42,0.08)]">
                        <div className="flex items-center justify-between gap-3">
                            <span className="rounded-full bg-emerald-50 px-3 py-1 text-[10px] font-semibold tracking-[0.22em] text-emerald-700 uppercase dark:bg-emerald-950/50 dark:text-emerald-300">
                                {property.status === 'active'
                                    ? 'Available'
                                    : property.status || 'Available'}
                            </span>
                            <span className="text-sm font-medium text-muted-foreground">
                                {property.currency || 'USD'}
                            </span>
                        </div>

                        <h1 className="mt-4 text-3xl font-semibold tracking-[-0.06em] text-foreground sm:text-4xl">
                            {property.title}
                        </h1>

                        <div className="mt-4 flex items-start gap-2 text-sm text-muted-foreground">
                            <span>📍</span>
                            <span className="min-w-0 break-words">
                                {locationLabel}
                            </span>
                        </div>

                        <div className="mt-6 rounded-[24px] border border-border bg-muted p-4">
                            <div className="text-xs tracking-[0.18em] text-muted-foreground uppercase">
                                From
                            </div>
                            <div className="mt-2">
                                <PriceDisplay
                                    amount={Number(
                                        property.price_per_night ?? 0,
                                    )}
                                    currency={property.currency ?? 'MUR'}
                                    suffix="/ night"
                                    className="text-4xl font-semibold tracking-[-0.06em] text-foreground"
                                />
                            </div>
                        </div>

                        <div className="mt-6 grid grid-cols-2 gap-3 text-center xl:grid-cols-4">
                            <div className="rounded-[18px] border border-border bg-muted p-2.5">
                                <div className="text-xl">👥</div>
                                <div className="mt-1.5 text-[8px] tracking-[0.14em] text-muted-foreground uppercase">
                                    Guests
                                </div>
                                <div className="mt-1 text-xs font-semibold break-words text-foreground">
                                    {property.max_guests || 4}
                                </div>
                            </div>
                            <div className="rounded-[18px] border border-border bg-muted p-2.5">
                                <div className="text-xl">🛏️</div>
                                <div className="mt-1.5 text-[8px] tracking-[0.14em] text-muted-foreground uppercase">
                                    Bedrooms
                                </div>
                                <div className="mt-1 text-xs font-semibold break-words text-foreground">
                                    {bedroomsLabel}
                                </div>
                            </div>
                            <div className="rounded-[18px] border border-border bg-muted p-2.5">
                                <div className="text-xl">🍳</div>
                                <div className="mt-1.5 text-[8px] tracking-[0.14em] text-muted-foreground uppercase">
                                    Kitchens
                                </div>
                                <div className="mt-1 text-xs font-semibold break-words text-foreground">
                                    {kitchensLabel}
                                </div>
                            </div>
                            <div className="rounded-[18px] border border-border bg-muted p-2.5">
                                <div className="text-xl">🛁</div>
                                <div className="mt-1.5 text-[8px] tracking-[0.14em] text-muted-foreground uppercase">
                                    Baths
                                </div>
                                <div className="mt-1 text-xs font-semibold break-words text-foreground">
                                    {bathroomsLabel}
                                </div>
                            </div>
                        </div>

                        <div className="mt-6 space-y-3 text-sm text-muted-foreground">
                            <div className="flex items-start justify-between gap-3 rounded-3xl border border-border bg-muted px-3 py-2">
                                <span>Check-in</span>
                                <span className="min-w-0 text-right font-medium break-words text-foreground">
                                    {checkInLabel}
                                </span>
                            </div>
                            <div className="flex items-start justify-between gap-3 rounded-3xl border border-border bg-muted px-3 py-2">
                                <span>Check-out</span>
                                <span className="min-w-0 text-right font-medium break-words text-foreground">
                                    {checkOutLabel}
                                </span>
                            </div>
                        </div>

                        <div className="mt-6 rounded-[24px] border border-border bg-muted p-4">
                            <div className="text-xs tracking-[0.18em] text-muted-foreground uppercase">
                                Search this stay
                            </div>
                            <div className="mt-4 grid gap-3">
                                <label>
                                    <span className="text-xs font-semibold tracking-[0.18em] text-muted-foreground uppercase">
                                        Check in
                                    </span>
                                    <input
                                        type="date"
                                        value={selectedCheckIn}
                                        min={todayIso}
                                        onChange={(event) =>
                                            handleCheckInChange(
                                                event.target.value,
                                            )
                                        }
                                        className="mt-2 w-full rounded-2xl border border-border bg-card px-4 py-3 text-sm text-foreground focus:border-ring focus:outline-none"
                                    />
                                </label>
                                <label>
                                    <span className="text-xs font-semibold tracking-[0.18em] text-muted-foreground uppercase">
                                        Check out
                                    </span>
                                    <input
                                        type="date"
                                        value={selectedCheckOut}
                                        min={minCheckOut}
                                        onChange={(event) =>
                                            setSelectedCheckOut(
                                                event.target.value,
                                            )
                                        }
                                        className="mt-2 w-full rounded-2xl border border-border bg-card px-4 py-3 text-sm text-foreground focus:border-ring focus:outline-none"
                                    />
                                </label>
                                <label>
                                    <span className="text-xs font-semibold tracking-[0.18em] text-muted-foreground uppercase">
                                        Guests
                                    </span>
                                    <input
                                        type="number"
                                        min={1}
                                        max={property.max_guests ?? undefined}
                                        value={selectedGuests}
                                        onChange={(event) =>
                                            setSelectedGuests(
                                                event.target.value,
                                            )
                                        }
                                        className="mt-2 w-full rounded-2xl border border-border bg-card px-4 py-3 text-sm text-foreground focus:border-ring focus:outline-none"
                                    />
                                </label>
                            </div>

                            <div className="mt-4 rounded-2xl border border-border bg-card px-4 py-3 text-sm text-muted-foreground">
                                {selectedRangeAvailable === null ? (
                                    <span>
                                        Select check-in and check-out dates to
                                        preview availability.
                                    </span>
                                ) : selectedRangeAvailable ? (
                                    <span className="font-medium text-emerald-700 dark:text-emerald-300">
                                        Available for {selectedNightCount} night
                                        {selectedNightCount === 1 ? '' : 's'}
                                        {totalStayPrice > 0
                                            ? ` • Estimated total ${formatPrice(totalStayPrice, property.currency)}`
                                            : ''}
                                    </span>
                                ) : selectedCheckIn < todayIso ? (
                                    <span className="font-medium text-rose-700 dark:text-rose-300">
                                        Check-in cannot be in the past. Pick
                                        today or a later date.
                                    </span>
                                ) : (
                                    <span className="font-medium text-rose-700 dark:text-rose-300">
                                        Those dates are not available. Please
                                        choose another range.
                                    </span>
                                )}
                            </div>
                        </div>

                        {!currentUser ? (
                            <div className="mt-4 text-xs text-muted-foreground">
                                Enter your name and email to go straight to
                                payment. You will choose a password afterwards.
                            </div>
                        ) : (
                            <div className="mt-4 text-xs text-emerald-700 dark:text-emerald-300">
                                Signed in as {currentUser.email}. Your
                                reservation will be added directly to your
                                booking history.
                            </div>
                        )}

                        {reservationError ? (
                            <div className="mt-3 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:border-rose-900/60 dark:bg-rose-950/50 dark:text-rose-300">
                                {reservationError}
                            </div>
                        ) : null}

                        <button
                            type="button"
                            onClick={handleReserveClick}
                            disabled={
                                selectedRangeAvailable !== true ||
                                reservationLoading
                            }
                            className="crystal-button mt-6 w-full justify-center bg-primary text-primary-foreground hover:bg-primary/90 disabled:cursor-not-allowed disabled:bg-muted disabled:text-muted-foreground"
                        >
                            {reservationLoading
                                ? 'Processing...'
                                : selectedRangeAvailable === false
                                  ? 'Choose other dates'
                                  : currentUser
                                    ? 'Reserve this stay'
                                    : 'Sign up & reserve'}
                        </button>

                        {!currentUser ? (
                            <a
                                href="/login"
                                className="mt-3 inline-flex text-sm font-medium text-foreground underline-offset-4 transition hover:text-foreground hover:underline"
                            >
                                Already have an account? Log in
                            </a>
                        ) : null}
                    </aside>
                </div>

                <section className="mb-8 rounded-[32px] border border-border bg-card p-6 shadow-[0_20px_60px_rgba(15,23,42,0.06)]">
                    <div className="text-xs tracking-[0.22em] text-muted-foreground uppercase">
                        Availability
                    </div>
                    <div className="mt-4 flex items-center justify-between gap-3">
                        <button
                            type="button"
                            onClick={() =>
                                setCalendarMonth((current) =>
                                    addMonths(current, -1),
                                )
                            }
                            disabled={calendarMonth <= currentMonthStart}
                            className="rounded-full border border-border px-3 py-1.5 text-sm font-medium text-foreground transition hover:border-foreground/20 hover:text-foreground disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:border-border"
                        >
                            Previous
                        </button>
                        <div className="text-sm text-muted-foreground">
                            Past and booked nights are greyed out. Pick your
                            dates above to check the stay.
                        </div>
                        <button
                            type="button"
                            onClick={() =>
                                setCalendarMonth((current) =>
                                    addMonths(current, 1),
                                )
                            }
                            className="rounded-full border border-border px-3 py-1.5 text-sm font-medium text-foreground transition hover:border-foreground/20 hover:text-foreground"
                        >
                            Next
                        </button>
                    </div>

                    <div className="mt-5 grid gap-5 md:grid-cols-2">
                        {calendarMonths.map((month) => (
                            <div
                                key={month.toISOString()}
                                className="rounded-[24px] border border-border bg-muted p-4"
                            >
                                <div className="mb-4 text-sm font-semibold tracking-[0.2em] text-muted-foreground uppercase">
                                    {month.toLocaleDateString(undefined, {
                                        month: 'long',
                                        year: 'numeric',
                                    })}
                                </div>
                                <div className="grid grid-cols-7 gap-2 text-center text-[11px] font-semibold tracking-[0.18em] text-muted-foreground uppercase">
                                    {weekdayLabels.map((label) => (
                                        <div key={label}>{label}</div>
                                    ))}
                                </div>
                                <div className="mt-2 grid grid-cols-7 gap-2 text-center text-sm">
                                    {buildCalendarDays(month).map(
                                        (day, index) => {
                                            if (!day) {
                                                return (
                                                    <div
                                                        key={`empty-${month.toISOString()}-${index}`}
                                                        className="h-11 rounded-2xl bg-transparent"
                                                    />
                                                );
                                            }

                                            const isoDate = toIsoDate(day);
                                            const isPast = isoDate < todayIso;
                                            const isBlocked = isDateBlocked(
                                                isoDate,
                                                blockedRanges,
                                            );
                                            const isSelected = Boolean(
                                                selectedCheckIn &&
                                                selectedCheckOut &&
                                                isoDate >= selectedCheckIn &&
                                                isoDate < selectedCheckOut,
                                            );
                                            const isToday =
                                                isoDate === todayIso;

                                            return (
                                                <div
                                                    key={isoDate}
                                                    aria-disabled={
                                                        isPast || isBlocked
                                                    }
                                                    className={`flex h-11 items-center justify-center rounded-2xl border text-sm font-medium ${isPast ? 'border-transparent bg-transparent text-muted-foreground/40 line-through' : isBlocked ? 'border-rose-200 bg-rose-50 text-rose-500 dark:border-rose-900/60 dark:bg-rose-950/50 dark:text-rose-400' : isSelected ? 'border-primary bg-primary text-primary-foreground' : 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/50 dark:text-emerald-400'} ${isToday && !isSelected ? 'ring-2 ring-ring/60' : ''}`}
                                                >
                                                    {day.getDate()}
                                                </div>
                                            );
                                        },
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>

                    <div className="mt-5 flex flex-wrap gap-3 text-sm text-muted-foreground">
                        <span className="inline-flex items-center gap-2">
                            <span className="h-3 w-3 rounded-full bg-primary" />{' '}
                            Selected stay
                        </span>
                        <span className="inline-flex items-center gap-2">
                            <span className="h-3 w-3 rounded-full bg-rose-200 dark:bg-rose-900/60" />{' '}
                            Booked night
                        </span>
                        <span className="inline-flex items-center gap-2">
                            <span className="h-3 w-3 rounded-full bg-emerald-200 dark:bg-emerald-900/60" />{' '}
                            Open night
                        </span>
                        <span className="inline-flex items-center gap-2">
                            <span className="h-3 w-3 rounded-full bg-muted-foreground/30" />{' '}
                            Past night
                        </span>
                    </div>
                </section>

                <div className="grid gap-8 lg:grid-cols-[1.05fr_0.95fr]">
                    <div className="space-y-8">
                        <section className="rounded-[32px] border border-border bg-card p-6 shadow-[0_20px_60px_rgba(15,23,42,0.06)]">
                            <div className="text-xs tracking-[0.22em] text-muted-foreground uppercase">
                                Stay layout
                            </div>
                            <div className="mt-4 grid gap-3 sm:grid-cols-3">
                                <div className="rounded-[20px] border border-border bg-muted p-4">
                                    <div className="text-xs tracking-[0.18em] text-muted-foreground uppercase">
                                        Bedrooms
                                    </div>
                                    <div className="mt-2 text-2xl font-semibold tracking-[-0.04em] text-foreground">
                                        {bedroomsLabel}
                                    </div>
                                    <p className="mt-2 text-sm text-muted-foreground">
                                        Sleeping spaces prepared for guests.
                                    </p>
                                </div>
                                <div className="rounded-[20px] border border-border bg-muted p-4">
                                    <div className="text-xs tracking-[0.18em] text-muted-foreground uppercase">
                                        Kitchens
                                    </div>
                                    <div className="mt-2 text-2xl font-semibold tracking-[-0.04em] text-foreground">
                                        {kitchensLabel}
                                    </div>
                                    <p className="mt-2 text-sm text-muted-foreground">
                                        Cooking areas or kitchenettes available
                                        in the stay.
                                    </p>
                                </div>
                                <div className="rounded-[20px] border border-border bg-muted p-4">
                                    <div className="text-xs tracking-[0.18em] text-muted-foreground uppercase">
                                        Bathrooms
                                    </div>
                                    <div className="mt-2 text-2xl font-semibold tracking-[-0.04em] text-foreground">
                                        {bathroomsLabel}
                                    </div>
                                    <p className="mt-2 text-sm text-muted-foreground">
                                        Private and shared bathing spaces.
                                    </p>
                                </div>
                            </div>
                        </section>

                        <section className="rounded-[32px] border border-border bg-card p-6 shadow-[0_20px_60px_rgba(15,23,42,0.06)]">
                            <div className="text-xs tracking-[0.22em] text-muted-foreground uppercase">
                                Room features
                            </div>
                            <div className="mt-4 space-y-4">
                                {groupedFeatures.length ? (
                                    groupedFeatures.map(
                                        ({ category, items }) => (
                                            <div key={category}>
                                                <div className="mb-2 text-[10px] font-semibold tracking-[0.22em] text-muted-foreground uppercase">
                                                    {category}
                                                </div>
                                                <div className="flex flex-wrap gap-2">
                                                    {items.map((feature) => (
                                                        <span
                                                            key={feature.name}
                                                            className="inline-flex items-center gap-2 rounded-full border border-border bg-muted px-3 py-1.5 text-xs font-medium text-foreground"
                                                        >
                                                            <span>
                                                                {feature.emoji}
                                                            </span>
                                                            {feature.name}
                                                        </span>
                                                    ))}
                                                </div>
                                            </div>
                                        ),
                                    )
                                ) : (
                                    <div className="rounded-full border border-dashed border-border bg-muted px-3 py-2 text-sm text-muted-foreground">
                                        No room features listed yet.
                                    </div>
                                )}
                            </div>
                        </section>
                    </div>

                    <div className="space-y-8">
                        {mapUrl ? (
                            <section className="overflow-hidden rounded-[32px] border border-border bg-card shadow-[0_20px_60px_rgba(15,23,42,0.06)]">
                                <div className="flex items-center justify-between border-b border-border px-5 py-4">
                                    <div>
                                        <div className="text-xs tracking-[0.22em] text-muted-foreground uppercase">
                                            Location
                                        </div>
                                        <h2 className="mt-1 text-xl font-semibold tracking-[-0.04em] text-foreground">
                                            Map view
                                        </h2>
                                    </div>
                                    <span className="rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-[10px] font-semibold tracking-[0.18em] text-emerald-700 uppercase dark:border-emerald-900/60 dark:bg-emerald-950/50 dark:text-emerald-300">
                                        OpenStreetMap
                                    </span>
                                </div>

                                <iframe
                                    title={`${property.title} location map`}
                                    src={mapUrl}
                                    width="100%"
                                    height="320"
                                    loading="lazy"
                                    referrerPolicy="no-referrer-when-downgrade"
                                    className="block w-full border-0"
                                />
                            </section>
                        ) : (
                            <section className="overflow-hidden rounded-[32px] border border-dashed border-border bg-card shadow-[0_20px_60px_rgba(15,23,42,0.06)]">
                                <div className="flex items-center justify-between border-b border-border px-5 py-4">
                                    <div>
                                        <div className="text-xs tracking-[0.22em] text-muted-foreground uppercase">
                                            Location
                                        </div>
                                        <h2 className="mt-1 text-xl font-semibold tracking-[-0.04em] text-foreground">
                                            Location unavailable
                                        </h2>
                                    </div>
                                    <span className="rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-[10px] font-semibold tracking-[0.18em] text-amber-700 uppercase dark:border-amber-900/60 dark:bg-amber-950/50 dark:text-amber-300">
                                        No coordinates
                                    </span>
                                </div>

                                <div className="relative flex min-h-[320px] flex-col items-center justify-center overflow-hidden bg-muted px-6 py-10 text-center">
                                    {/* faint grid, so the empty state still reads as a map */}
                                    <div
                                        aria-hidden="true"
                                        className="pointer-events-none absolute inset-0 [background-image:linear-gradient(to_right,var(--color-border)_1px,transparent_1px),linear-gradient(to_bottom,var(--color-border)_1px,transparent_1px)] [background-size:32px_32px] opacity-[0.35]"
                                    />

                                    <div className="relative flex h-20 w-20 items-center justify-center rounded-full border border-amber-200 bg-card shadow-sm dark:border-amber-900/60">
                                        <BrokenMapPin className="h-11 w-11 text-amber-600 dark:text-amber-400" />
                                    </div>

                                    <h3 className="relative mt-6 text-xl font-semibold text-foreground">
                                        This stay has no map pin yet
                                    </h3>
                                    <p className="relative mt-3 max-w-md text-sm leading-7 text-muted-foreground">
                                        {property.address
                                            ? 'The host added an address, but exact coordinates are still missing, so the map preview cannot be shown yet.'
                                            : 'No address or coordinates have been added for this property yet, so the location preview is currently unavailable.'}
                                    </p>

                                    <div className="relative mt-6 rounded-full border border-border bg-card px-4 py-2 text-sm font-medium text-foreground shadow-sm">
                                        {property.address ||
                                            'Location details pending'}
                                    </div>
                                </div>
                            </section>
                        )}
                    </div>
                </div>

                {amenities.length ? (
                    <section className="mt-8 rounded-[32px] border border-border bg-card p-6 shadow-[0_20px_60px_rgba(15,23,42,0.06)]">
                        <div className="text-xs tracking-[0.22em] text-muted-foreground uppercase">
                            Amenities
                        </div>
                        <div className="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                            {amenities.map((item: string) => (
                                <div
                                    key={item}
                                    className="flex min-h-14 items-center gap-3 rounded-[18px] border border-border bg-muted px-4 py-3 text-sm font-medium text-foreground"
                                >
                                    <span className="text-base">✓</span>
                                    <span className="break-words">{item}</span>
                                </div>
                            ))}
                        </div>
                    </section>
                ) : null}

                <PropertyPolicies property={property} />

                <section className="mt-8 rounded-[32px] border border-border bg-card p-6 shadow-[0_20px_60px_rgba(15,23,42,0.06)]">
                    <div className="flex items-end justify-between gap-4">
                        <div>
                            <div className="text-xs tracking-[0.22em] text-muted-foreground uppercase">
                                Guest reviews
                            </div>
                            <h2 className="mt-2 text-2xl font-semibold tracking-[-0.04em] text-foreground">
                                What recent guests said
                            </h2>
                        </div>
                        <div className="rounded-full border border-border bg-muted px-4 py-2 text-sm font-medium text-foreground">
                            {propertyReviews.length
                                ? `${propertyReviews.length} review${propertyReviews.length === 1 ? '' : 's'}`
                                : 'No reviews yet'}
                        </div>
                    </div>

                    <div className="mt-6 grid gap-4 lg:grid-cols-2">
                        {propertyReviews.length ? (
                            propertyReviews.map((review) => (
                                <article
                                    key={review.id}
                                    className="rounded-[24px] border border-border bg-muted p-5"
                                >
                                    <div className="flex items-center justify-between gap-3">
                                        <div>
                                            <div className="text-sm font-semibold text-foreground">
                                                {review.client?.name ||
                                                    'Verified guest'}
                                            </div>
                                            <div className="mt-1 text-xs tracking-[0.14em] text-muted-foreground uppercase">
                                                {review.created_at
                                                    ? new Date(
                                                          review.created_at,
                                                      ).toLocaleDateString(
                                                          undefined,
                                                          {
                                                              month: 'short',
                                                              day: 'numeric',
                                                              year: 'numeric',
                                                          },
                                                      )
                                                    : 'Recent stay'}
                                            </div>
                                        </div>
                                        <div className="text-sm font-semibold text-amber-600 dark:text-amber-400">
                                            {'★'.repeat(review.rating ?? 0)}
                                            {'☆'.repeat(
                                                Math.max(
                                                    0,
                                                    5 - (review.rating ?? 0),
                                                ),
                                            )}
                                        </div>
                                    </div>
                                    <p className="mt-4 text-sm leading-7 text-muted-foreground">
                                        {review.comment ||
                                            'Guest left a rating without extra notes.'}
                                    </p>
                                </article>
                            ))
                        ) : (
                            <div className="rounded-[24px] border border-dashed border-border bg-muted px-5 py-8 text-sm text-muted-foreground lg:col-span-2">
                                No guest reviews have been published for this
                                property yet.
                            </div>
                        )}
                    </div>
                </section>

                <section className="mt-8 rounded-[32px] border border-border bg-card p-6 shadow-[0_20px_60px_rgba(15,23,42,0.06)]">
                    <div className="flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
                        <div>
                            <div className="text-xs tracking-[0.22em] text-muted-foreground uppercase">
                                Room gallery
                            </div>
                            <h2 className="mt-2 text-2xl font-semibold tracking-[-0.04em] text-foreground">
                                Browse every room in the carousel
                            </h2>
                            <p className="mt-2 max-w-3xl text-sm text-muted-foreground">
                                Open a room below to jump into the full photo
                                carousel without repeating the same images on
                                the page.
                            </p>
                        </div>
                        <button
                            type="button"
                            onClick={() => openGallery(0)}
                            className="inline-flex items-center justify-center rounded-full border border-border bg-muted px-4 py-2 text-sm font-medium text-foreground transition hover:border-foreground/20 hover:bg-accent"
                        >
                            Show all photos
                        </button>
                    </div>

                    <div className="mt-6 space-y-6">
                        {(
                            Object.entries(groupedRoomPhotos) as [
                                string,
                                PropertyImageItem[],
                            ][]
                        ).map(([roomType, images]) => (
                            <button
                                key={roomType}
                                type="button"
                                onClick={() =>
                                    openGallery(getGalleryIndex(images[0]))
                                }
                                className="flex w-full items-start justify-between gap-4 rounded-[24px] border border-border bg-muted px-5 py-4 text-left transition hover:border-foreground/20 hover:bg-accent"
                            >
                                <div>
                                    <h3 className="text-lg font-semibold text-foreground">
                                        {ROOM_TYPE_LABELS[roomType] ||
                                            'Room photos'}
                                    </h3>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {images.length} photo
                                        {images.length > 1 ? 's' : ''} in the
                                        full carousel
                                    </p>
                                </div>
                                <span className="shrink-0 rounded-full border border-border bg-card px-3 py-1 text-xs font-medium text-muted-foreground">
                                    View photos
                                </span>
                            </button>
                        ))}
                    </div>
                </section>
            </div>

            <Dialog
                open={reservationDialogOpen}
                onOpenChange={(open) =>
                    !reservationLoading &&
                    (!open
                        ? resetReservationFlow()
                        : setReservationDialogOpen(true))
                }
            >
                <DialogContent className="max-w-lg border-border bg-card text-foreground">
                    <DialogHeader>
                        <DialogTitle>
                            {reservationStep === 'account'
                                ? 'Create your account to reserve'
                                : reservationStep === 'login'
                                  ? 'Log in to finish your reservation'
                                  : 'Reservation confirmed'}
                        </DialogTitle>
                        <DialogDescription>
                            {reservationStep === 'account'
                                ? 'Enter your name and email to hold these dates and go straight to payment. You will choose a password once you have paid.'
                                : reservationStep === 'login'
                                  ? `${reservationOtpEmail} already has an account. Enter your password and we will reserve these dates right away.`
                                  : 'Your booking and account are ready. You can now view your payment, receipt, and history.'}
                        </DialogDescription>
                    </DialogHeader>

                    {reservationStep === 'account' ? (
                        <div className="space-y-4">
                            <label className="block text-sm text-foreground">
                                <span className="mb-2 block font-medium">
                                    Full name
                                </span>
                                <input
                                    type="text"
                                    value={reservationAccount.name}
                                    onChange={(event) =>
                                        setReservationAccount((current) => ({
                                            ...current,
                                            name: event.target.value,
                                        }))
                                    }
                                    className="w-full rounded-2xl border border-border px-4 py-3 text-sm focus:border-ring focus:outline-none"
                                />
                            </label>
                            <label className="block text-sm text-foreground">
                                <span className="mb-2 block font-medium">
                                    Email
                                </span>
                                <input
                                    type="email"
                                    value={reservationAccount.email}
                                    onChange={(event) =>
                                        setReservationAccount((current) => ({
                                            ...current,
                                            email: event.target.value,
                                        }))
                                    }
                                    className="w-full rounded-2xl border border-border px-4 py-3 text-sm focus:border-ring focus:outline-none"
                                />
                            </label>

                            {reservationError ? (
                                <div className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:border-rose-900/60 dark:bg-rose-950/50 dark:text-rose-300">
                                    {reservationError}
                                </div>
                            ) : null}

                            <button
                                type="button"
                                onClick={handleStartGuestReservation}
                                disabled={reservationLoading}
                                className="crystal-button w-full justify-center bg-primary text-primary-foreground hover:bg-primary/90 disabled:bg-muted disabled:text-muted-foreground"
                            >
                                {reservationLoading
                                    ? 'Reserving…'
                                    : 'Continue to payment'}
                            </button>
                        </div>
                    ) : null}

                    {reservationStep === 'login' ? (
                        <div className="space-y-4">
                            <label className="block text-sm text-foreground">
                                <span className="mb-2 block font-medium">
                                    Password
                                </span>
                                <input
                                    type="password"
                                    autoComplete="current-password"
                                    value={reservationAccount.password}
                                    onChange={(event) =>
                                        setReservationAccount((current) => ({
                                            ...current,
                                            password: event.target.value,
                                        }))
                                    }
                                    className="w-full rounded-2xl border border-border px-4 py-3 text-sm focus:border-ring focus:outline-none"
                                />
                            </label>

                            {reservationError ? (
                                <div className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:border-rose-900/60 dark:bg-rose-950/50 dark:text-rose-300">
                                    {reservationError}
                                </div>
                            ) : null}

                            <button
                                type="button"
                                onClick={handleLoginAndReserve}
                                disabled={
                                    reservationLoading ||
                                    !reservationAccount.password
                                }
                                className="crystal-button w-full justify-center bg-primary text-primary-foreground hover:bg-primary/90 disabled:bg-muted disabled:text-muted-foreground"
                            >
                                {reservationLoading
                                    ? 'Reserving...'
                                    : 'Log in and reserve'}
                            </button>

                            <a
                                href="/forgot-password"
                                className="block text-center text-sm text-muted-foreground underline-offset-4 hover:underline"
                            >
                                Forgot your password?
                            </a>
                        </div>
                    ) : null}

                    {reservationStep === 'success' ? (
                        <div className="space-y-4">
                            <div className="rounded-[24px] border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm text-emerald-800 dark:border-emerald-900/60 dark:bg-emerald-950/50 dark:text-emerald-200">
                                Booking #{reservationResult?.booking?.id} is
                                ready for {reservationResult?.booking?.check_in}{' '}
                                to {reservationResult?.booking?.check_out}.
                            </div>
                            <div className="rounded-[24px] border border-border bg-muted px-5 py-4 text-sm text-foreground">
                                <div>
                                    Total:{' '}
                                    {formatPrice(
                                        reservationResult?.payment?.amount ??
                                            reservationResult?.booking
                                                ?.total_price ??
                                            0,
                                        property.currency,
                                    )}
                                </div>
                                <div className="mt-2">
                                    Payment status:{' '}
                                    {reservationResult?.payment?.status ||
                                        'pending'}
                                </div>
                            </div>
                            <div className="flex gap-3">
                                <a
                                    href={
                                        reservationResult?.redirect_url ||
                                        '/client/bookings'
                                    }
                                    className="crystal-button flex-1 justify-center bg-primary text-primary-foreground hover:bg-primary/90"
                                >
                                    View booking history
                                </a>
                                <button
                                    type="button"
                                    onClick={resetReservationFlow}
                                    className="flex-1 rounded-2xl border border-border px-4 py-3 text-sm font-medium text-foreground transition hover:border-foreground/20 hover:text-foreground"
                                >
                                    Close
                                </button>
                            </div>
                        </div>
                    ) : null}
                </DialogContent>
            </Dialog>

            <Dialog open={galleryOpen} onOpenChange={setGalleryOpen}>
                <DialogContent className="max-w-5xl border-border bg-card p-0 text-foreground md:max-w-6xl">
                    <DialogHeader className="sr-only">
                        <DialogTitle>Property gallery</DialogTitle>
                        <DialogDescription>
                            Browse the room gallery for this property.
                        </DialogDescription>
                    </DialogHeader>

                    {selectedGalleryImage ? (
                        <div className="relative overflow-hidden rounded-3xl bg-card">
                            <div className="flex items-center justify-between gap-3 border-b border-border px-4 py-3 pr-14 text-sm text-muted-foreground md:px-6 md:pr-20">
                                <span>
                                    {selectedGalleryImage.room_name ||
                                        'Room photo'}
                                </span>
                                <span className="shrink-0">
                                    {galleryIndex + 1} / {propertyImages.length}
                                </span>
                            </div>

                            <div className="flex items-center gap-3 px-3 py-3 md:px-6">
                                <button
                                    type="button"
                                    onClick={showPreviousGalleryImage}
                                    className="inline-flex h-10 w-10 items-center justify-center rounded-full border border-border bg-muted text-xl text-foreground transition hover:border-foreground/20 hover:bg-accent"
                                    aria-label="Previous image"
                                >
                                    ←
                                </button>

                                <div className="relative flex-1 overflow-hidden rounded-2xl bg-muted">
                                    <img
                                        src={selectedGalleryImage.url}
                                        alt={
                                            selectedGalleryImage.alt_text ||
                                            selectedGalleryImage.room_name ||
                                            property.title
                                        }
                                        className="h-[60vh] w-full object-contain"
                                    />
                                </div>

                                <button
                                    type="button"
                                    onClick={showNextGalleryImage}
                                    className="inline-flex h-10 w-10 items-center justify-center rounded-full border border-border bg-muted text-xl text-foreground transition hover:border-foreground/20 hover:bg-accent"
                                    aria-label="Next image"
                                >
                                    →
                                </button>
                            </div>

                            <div className="px-3 pb-4 md:px-6 md:pb-6">
                                <div className="flex gap-3 overflow-x-auto pb-1">
                                    {propertyImages.map((image, index) => (
                                        <button
                                            key={`${image.url}-${index}`}
                                            type="button"
                                            onClick={() =>
                                                setGalleryIndex(index)
                                            }
                                            className={`h-20 w-24 shrink-0 overflow-hidden rounded-xl border ${galleryIndex === index ? 'border-primary ring-2 ring-ring' : 'border-border'} bg-muted`}
                                            aria-label={`View image ${index + 1}`}
                                        >
                                            <img
                                                src={image.url}
                                                alt={
                                                    image.alt_text ||
                                                    image.room_name ||
                                                    `Gallery image ${index + 1}`
                                                }
                                                className="h-full w-full object-contain"
                                            />
                                        </button>
                                    ))}
                                </div>

                                <p className="mt-4 text-sm text-muted-foreground">
                                    {selectedGalleryImage.alt_text ||
                                        selectedGalleryImage.room_name ||
                                        'Room preview from this stay.'}
                                </p>
                            </div>
                        </div>
                    ) : null}
                </DialogContent>
            </Dialog>
        </div>
    );
}
