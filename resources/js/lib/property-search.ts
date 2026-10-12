export type PropertyImageItem = {
    id?: number;
    url: string;
    image_path?: string;
    room_type?: string | null;
    room_name?: string | null;
    alt_text?: string | null;
    order?: number;
    is_cover?: boolean;
};

export type PropertyReviewItem = {
    id?: number;
    rating?: number;
    comment?: string | null;
    status?: string | null;
    created_at?: string | null;
    client?: {
        id?: number;
        name?: string | null;
    } | null;
};

export type PublicProperty = {
    id?: number;
    title?: string;
    slug?: string;
    description?: string | null;
    address?: string | null;
    lat?: number | string | null;
    lng?: number | string | null;
    check_in?: string | null;
    check_out?: string | null;
    price_per_night?: number | string | null;
    currency?: string | null;
    max_guests?: number | null;
    bedrooms?: number | null;
    kitchens?: number | null;
    bathrooms?: number | null;
    amenities?: string[] | null;
    features?: string[] | null;
    status?: string | null;
    images?: Array<string | PropertyImageItem> | null;
    average_rating?: number | null;
    reviews?: PropertyReviewItem[] | null;
    /** Authored per property in the back office, rich text. */
    terms_and_conditions?: string | null;
    cancellation_policy?: string | null;
    cancellation_fee_percent?: number | null;
    vat?: { total: number; net: number; vat: number; rate: number } | null;
};

export type PropertySearchFilters = {
    q: string;
    checkIn: string;
    checkOut: string;
    guests: string;
    maxPrice: string;
};

export const defaultPropertySearchFilters: PropertySearchFilters = {
    q: '',
    checkIn: '',
    checkOut: '',
    guests: '',
    maxPrice: '',
};

const fallbackPropertyImage =
    'https://images.unsplash.com/photo-1505693416388-ac5ce068fe85?auto=format&fit=crop&w=1200&q=80';

export const formatPrice = (
    value: number | string | null | undefined,
    currency: string | null | undefined = 'USD',
) => {
    const amount = Number(value ?? 0);

    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: currency || 'USD',
        maximumFractionDigits: 0,
    }).format(amount);
};

export const formatRoomLine = (property: PublicProperty) => {
    const parts = [
        property?.bedrooms
            ? `${property.bedrooms} bedroom${property.bedrooms > 1 ? 's' : ''}`
            : null,
        property?.kitchens !== null && property?.kitchens !== undefined
            ? `${property.kitchens} kitchen${property.kitchens === 1 ? '' : 's'}`
            : null,
        property?.bathrooms
            ? `${property.bathrooms} bathroom${property.bathrooms > 1 ? 's' : ''}`
            : null,
    ].filter(Boolean);

    return parts.length ? parts.join(' • ') : 'Room layout not added yet.';
};

export const toImageUrl = (image: string | Record<string, unknown>) => {
    if (typeof image === 'string') {
        return image;
    }

    const url = typeof image?.url === 'string' ? image.url : null;
    const imagePath =
        typeof image?.image_path === 'string' ? image.image_path : null;

    if (url) {
        return url;
    }

    if (imagePath) {
        return imagePath.startsWith('http')
            ? imagePath
            : `/storage/${imagePath}`;
    }

    return fallbackPropertyImage;
};

export const normalizePropertyImages = (
    property: PublicProperty | null | undefined,
): PropertyImageItem[] => {
    const images = Array.isArray(property?.images) ? property.images : [];

    if (images.length === 0) {
        return [
            {
                url: fallbackPropertyImage,
                room_type: 'other',
                room_name: 'Property preview',
                alt_text: 'Property preview',
            },
        ];
    }

    return images.map((image): PropertyImageItem => {
        if (typeof image === 'string') {
            return {
                url: image,
                room_type: 'other',
            };
        }

        return {
            id: image?.id,
            url: toImageUrl(image),
            image_path: image?.image_path,
            room_type: image?.room_type,
            room_name: image?.room_name,
            alt_text: image?.alt_text,
            order: image?.order,
            is_cover: image?.is_cover,
        };
    });
};

export const normalizePropertyImage = (
    property: PublicProperty | null | undefined,
): string => {
    const images = Array.isArray(property?.images) ? property.images : [];
    const chosen =
        images.find((image) => typeof image === 'object' && image?.is_cover) ??
        images[0] ??
        null;

    if (typeof chosen === 'string') {
        return chosen;
    }

    if (chosen && typeof chosen === 'object') {
        return toImageUrl(chosen);
    }

    return fallbackPropertyImage;
};

export const readPropertySearchFilters = (
    search: string,
): PropertySearchFilters => {
    const params = new URLSearchParams(search);

    return {
        q: params.get('q') ?? '',
        checkIn: params.get('check_in') ?? '',
        checkOut: params.get('check_out') ?? '',
        guests: params.get('guests') ?? '',
        maxPrice: params.get('max_price') ?? '',
    };
};

const writePropertySearchParams = (filters: PropertySearchFilters) => {
    const params = new URLSearchParams();

    if (filters.q.trim()) {
        params.set('q', filters.q.trim());
    }

    if (filters.checkIn) {
        params.set('check_in', filters.checkIn);
    }

    if (filters.checkOut) {
        params.set('check_out', filters.checkOut);
    }

    if (filters.guests) {
        params.set('guests', filters.guests);
    }

    if (filters.maxPrice) {
        params.set('max_price', filters.maxPrice);
    }

    return params;
};

export const buildPropertiesUrl = (filters: PropertySearchFilters) => {
    const params = writePropertySearchParams(filters).toString();

    return params ? `/properties?${params}` : '/properties';
};

export const buildPropertyUrl = (
    slug: string,
    filters: PropertySearchFilters,
) => {
    const params = writePropertySearchParams(filters).toString();

    return params ? `/properties/${slug}?${params}` : `/properties/${slug}`;
};

export const toApiSearchParams = (
    filters: PropertySearchFilters,
): Record<string, string | number | boolean> => {
    const params: Record<string, string | number | boolean> = {};

    if (filters.q.trim()) {
        params.q = filters.q.trim();
    }

    if (filters.checkIn) {
        params.check_in = filters.checkIn;
    }

    if (filters.checkOut) {
        params.check_out = filters.checkOut;
    }

    if (filters.guests) {
        params.guests = filters.guests;
    }

    if (filters.maxPrice) {
        params.max_price = filters.maxPrice;
    }

    return params;
};

/** Today in YYYY-MM-DD, local time - the floor for any date input. */
export const todayIsoDate = () => {
    const now = new Date();
    const pad = (value: number) => String(value).padStart(2, '0');

    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
};

/** The day after the given ISO date, used as the check-out floor. */
export const nextIsoDate = (isoDate: string) => {
    const [year, month, day] = isoDate.split('-').map(Number);
    const next = new Date(year, month - 1, day + 1);
    const pad = (value: number) => String(value).padStart(2, '0');

    return `${next.getFullYear()}-${pad(next.getMonth() + 1)}-${pad(next.getDate())}`;
};
